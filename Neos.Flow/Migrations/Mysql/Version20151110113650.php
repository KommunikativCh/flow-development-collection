<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Add the two additional fields lastsuccessfulauthenticationdate and failedauthenticationcount to
 * table typo3_flow_security_account
 */
class Version20151110113650 extends AbstractMigration
{

    /**
     * @param Schema $schema
     * @return void
     */
    public function up(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof MySQLPlatform));

        $this->addSql('ALTER TABLE typo3_flow_security_account ADD lastsuccessfulauthenticationdate DATETIME DEFAULT NULL, ADD failedauthenticationcount INT DEFAULT 0');
    }

    /**
     * @param Schema $schema
     * @return void
     */
    public function down(Schema $schema): void
    {
        $this->abortIf(!($this->connection->getDatabasePlatform() instanceof MySQLPlatform));

        $this->addSql('ALTER TABLE typo3_flow_security_account DROP lastsuccessfulauthenticationdate, DROP failedauthenticationcount');
    }
}
