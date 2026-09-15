<?php

declare(strict_types=1);

namespace App\Migrations;

use Articulate\Modules\Migrations\Generator\BaseMigration;

class Migration20260908000600OptimisticLocking extends BaseMigration
{
    protected function up(): void
    {
        $this->addSql('CREATE TABLE `invoices` (`id` INT UNSIGNED AUTO_INCREMENT NOT NULL, `number` VARCHAR(32) NOT NULL, `title` VARCHAR(160) NOT NULL, `notes` VARCHAR(500), `amount` DOUBLE NOT NULL DEFAULT \'0\', `status` VARCHAR(32) NOT NULL DEFAULT \'draft\', `version` INT NOT NULL DEFAULT \'0\', PRIMARY KEY (`id`))');
    }

    protected function down(): void
    {
        $this->addSql('DROP TABLE `invoices`');
    }
}
