<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Container\EntityManagerAwareTrait;
use App\Entity\Repository\StationQueueRepository;
use App\Entity\Station;
use App\Entity\StationMedia;
use App\Entity\StationMediaMetadata;
use App\Entity\StationQueue;
use App\Entity\StationRequest;
use App\Event\Radio\AnnotateNextSong;
use App\Radio\Backend\Liquidsoap\ConfigWriter;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class Annotations implements EventSubscriberInterface
{
    use EntityManagerAwareTrait;

    public function __construct(
        private readonly StationQueueRepository $queueRepo,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AnnotateNextSong::class => [
                ['annotateSongPath', 20],
                ['annotateForLiquidsoap', 15],
                ['annotatePlaylist', 10],
                ['annotateRequest', 5],
                ['postAnnotation', -10],
            ],
        ];
    }

    /**
     * Pulls the next song from the AutoDJ, dispatches the AnnotateNextSong event and returns the built result.
     */
    public function annotateNextSong(
        Station $station,
        bool $asAutoDj = false,
    ): string|bool {
        $queueRow = $this->queueRepo->getNextToSendToAutoDj($station);

        if (null === $queueRow) {
            return false;
        }

        $event = AnnotateNextSong::fromStationQueue($queueRow, $asAutoDj);
        $this->eventDispatcher->dispatch($event);

        return $event->buildAnnotations();
    }

    public function annotateSongPath(AnnotateNextSong $event): void
    {
        $media = $event->getMedia();
        if ($media instanceof StationMedia) {
            $event->setSongPath('media:' . ltrim($media->getPath(), '/'));
        } else {
            $queue = $event->getQueue();
            if ($queue instanceof StationQueue) {
                $customUri = $queue->getAutodjCustomUri();
                if (!empty($customUri)) {
                    $event->setSongPath($customUri);
                }
            }
        }
    }

    public function annotateForLiquidsoap(AnnotateNextSong $event): void
    {
        $media = $event->getMedia();
        if (null === $media) {
            return;
        }

        $station = $event->getStation();
        if (!$station->getBackendType()->isEnabled()) {
            return;
        }

        $duration = $media->getLength();

        $annotations = array_filter([
            'title' => $media->getTitle(),
            'artist' => $media->getArtist(),
            'duration' => $duration,
            'song_id' => $media->getSongId(),
            'media_id' => $media->getId(),
            ...$this->processAutocueAnnotations(
                $station,
                $media->getExtraMetadata()->toAnnotations($duration),
                $duration,
            ),
        ], fn ($row) => ('' !== $row && null !== $row));

        $event->addAnnotations(
            array_map([ConfigWriter::class, 'valueToString'], $annotations)
        );
    }

    private function processAutocueAnnotations(
        Station $station,
        array $annotations,
        float $duration,
    ): array {
        if (0 === count($annotations)) {
            return [];
        }

        $backendConfig = $station->getBackendConfig();

        if ($backendConfig->getEnableAutoCue()) {
            // Directly write annotations as `liq_` values (pre-2.3.x)
            $annotationsNew = [];
            foreach ($annotations as $key => $val) {
                $key = 'liq_' . $key;
                $annotationsNew[$key] = $val;
            }

            return $annotationsNew;
        }

        // Ensure default values for all annotations.
        $annotations[StationMediaMetadata::CUE_IN] ??= 0.0;
        $annotations[StationMediaMetadata::CUE_OUT] ??= $duration;

        $defaultFade = $backendConfig->isCrossfadeEnabled()
            ? $backendConfig->getCrossfade()
            : 0.0;

        $annotations[StationMediaMetadata::FADE_IN] ??= $defaultFade;
        $annotations[StationMediaMetadata::FADE_OUT] ??= $defaultFade;

        if (!isset($annotations[StationMediaMetadata::CROSS_START_NEXT])) {
            $defaultStartNext = $backendConfig->isCrossfadeEnabled()
                ? $duration - $backendConfig->getCrossfadeDuration()
                : $duration;

            if ($defaultStartNext < 0) {
                $defaultStartNext = $duration;
            }

            $annotations[StationMediaMetadata::CROSS_START_NEXT] = $defaultStartNext;
        }

        $annotationMapping = [
            StationMediaMetadata::AMPLIFY => 'azuracast_amplify',
            StationMediaMetadata::CUE_IN => 'azuracast_cue_in',
            StationMediaMetadata::CUE_OUT => 'azuracast_cue_out',
            StationMediaMetadata::FADE_IN => 'azuracast_fade_in',
            StationMediaMetadata::FADE_OUT => 'azuracast_fade_out',
            StationMediaMetadata::CROSS_START_NEXT => 'azuracast_start_next',
        ];

        $annotationsNew = [
            'azuracast_autocue' => true,
        ];

        foreach ($annotations as $key => $value) {
            if (isset($annotationMapping[$key])) {
                $annotationsNew[$annotationMapping[$key]] = $value;
            }
        }

        return $annotationsNew;
    }

    public function annotatePlaylist(AnnotateNextSong $event): void
    {
        $playlist = $event->getPlaylist();
        if (null === $playlist) {
            return;
        }

        $event->addAnnotations([
            'playlist_id' => $playlist->getId(),
        ]);

        if ($playlist->getIsJingle()) {
            $event->addAnnotations([
                'jingle_mode' => 'true',
            ]);
        }
    }

    public function annotateRequest(AnnotateNextSong $event): void
    {
        $request = $event->getRequest();
        if ($request instanceof StationRequest) {
            $event->addAnnotations([
                'request_id' => $request->getId(),
            ]);
        }
    }

    public function postAnnotation(AnnotateNextSong $event): void
    {
        if (!$event->isAsAutoDj()) {
            return;
        }

        $queueRow = $event->getQueue();
        if ($queueRow instanceof StationQueue) {
            $queueRow->setSentToAutodj();
            $queueRow->setTimestampCued(time());
            $this->em->persist($queueRow);
            $this->em->flush();
        }
    }
}
