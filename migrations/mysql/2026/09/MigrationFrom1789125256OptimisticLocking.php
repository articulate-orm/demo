<?php

declare(strict_types=1);

namespace App\Migrations;

use Articulate\Modules\Migrations\Generator\BaseMigration;

class MigrationFrom1789125256OptimisticLocking extends BaseMigration
{
    protected function up(): void
    {
        $this->addSql('CREATE TABLE `documents` (`id` INT UNSIGNED AUTO_INCREMENT NOT NULL, `view_count` INT NOT NULL DEFAULT \'0\', `title` VARCHAR(120) NOT NULL DEFAULT \'\', `tags` VARCHAR(255) NOT NULL DEFAULT \'\', `version_metadata` INT NOT NULL DEFAULT \'0\', `content` VARCHAR(255) NOT NULL, `version_content` INT NOT NULL DEFAULT \'0\', `version_content_alt` INT NOT NULL DEFAULT \'0\', PRIMARY KEY (`id`))');
    }

    protected function down(): void
    {
        $this->addSql('DROP TABLE `documents`');
    }
}
