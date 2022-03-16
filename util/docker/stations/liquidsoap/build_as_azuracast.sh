#!/bin/bash
set -e
set -x

opam init --disable-sandboxing -a --bare && opam switch create 4.13.1

# Pin specific commit of Liquidsoap
opam pin add --no-action liquidsoap https://github.com/savonet/liquidsoap.git#c6eabcba0c198cead893e742ef59c3ff8c9e2137

opam pin add --no-action mm https://github.com/savonet/ocaml-mm.git#bfff160ece1676a3a912e8bc79c80ce6482f4d36

opam pin add --no-action ffmpeg https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-av https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-avcodec https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-avdevice https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-avfilter https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-avutil https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-swresample https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578
opam pin add --no-action ffmpeg-swscale https://github.com/savonet/ocaml-ffmpeg.git#7ebc5f76d607c50b410ad7fc6c5a6c90639e6578

opam install -y ladspa.0.2.2 ffmpeg ffmpeg-avutil ffmpeg-avcodec ffmpeg-avdevice \
    ffmpeg-av ffmpeg-avfilter ffmpeg-swresample ffmpeg-swscale frei0r.0.1.2 \
    samplerate.0.1.6 taglib.0.3.9 mad.0.5.2 faad.0.5.0 fdkaac.0.3.2 lame.0.3.5 vorbis.0.8.0 cry.0.6.6 \
    flac.0.3.0 opus.0.2.1 dtools.0.4.4 duppy.0.9.2 ocurl.0.9.2 ssl.0.5.10 \
    liquidsoap

# Have Liquidsoap build its own chroot.
mkdir -p /tmp/liquidsoap

/var/azuracast/.opam/4.13.1/bin/liquidsoap /bd_build/stations/liquidsoap/build_chroot.liq || true

# Clear entire OPAM directory
rm -rf /var/azuracast/.opam

cp -r /tmp/liquidsoap/var/azuracast/.opam /var/azuracast/.opam
rm -rf /tmp/liquidsoap
