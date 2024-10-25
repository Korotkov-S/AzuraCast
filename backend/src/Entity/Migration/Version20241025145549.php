<?php

declare(strict_types=1);

namespace App\Entity\Migration;

use Doctrine\DBAL\Schema\Schema;

final class Version20241025145549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_requests ADD first_name VARCHAR(255) DEFAULT NULL, ADD email VARCHAR(255) NOT NULL, ADD text LONGTEXT DEFAULT NULL, CHANGE track_id track_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE station_requests DROP first_name, DROP email, DROP text, CHANGE track_id track_id INT NOT NULL');
    }
}
