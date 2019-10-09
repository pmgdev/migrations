<?php declare(strict_types=1);

namespace PmgDev\Migrations\Command;

use h4kuna\Memoize\MemoryStorage;
use Nette\Utils;
use PmgDev\PsqlCli;
use PmgDev\GitCli;
use PmgDev\Migrations\Config;
use PmgDev\Migrations\Factory;
use Symfony\Component\Console\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;

final class CompareStructure extends Command\Command
{
	use MemoryStorage;

	private const MASTER_DB = 'pmg_compare_master';
	private const HEAD_DB  = 'pmg_compare_head';
	private const TEMP_HEAD = 'pmg_temp_head.sql';
	private const TEMP_MIGRATION = 'pmg_temp_migration.sql';

	/** @var string */
	private $structureFilename;

	/** @var string */
	private $migrationsDir;

	/** @var string */
	private $tempDir;

	/** @var PsqlCli\Command */
	private $psql;

	/** @var GitCli\Command */
	private $git;


	public function __construct(?string $tempDir = NULL)
	{
		$tempDir = rtrim($tempDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
		$this->tempDir = $tempDir . DIRECTORY_SEPARATOR . 'pmg-migrations-cs';
		parent::__construct('compare-structure');
	}


	protected function configure(): void
	{
		$this->setDescription('Compare actual SQL structure file with GIT master (default, or selected commit) SQL structure file')
			->addOption('config', NULL, Input\InputOption::VALUE_REQUIRED)
			->addOption('commit-id', NULL, Input\InputOption::VALUE_OPTIONAL, 'SHA1 commit hash to compare (master is default)')
			->addArgument('files', Input\InputArgument::IS_ARRAY, 'file(s) for update structure');
	}


	public function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		/** @var string $configOption */
		$configOption = $input->getOption('config');
		$config = new Config($configOption);
		$factory = new Factory($config);
		['structureFilename' => $this->structureFilename, 'migrationsDir' => $this->migrationsDir] = $config->parameters();
		$this->git = $factory->createGit();
		$this->psql = $factory->createPsql();

		if ($this->findFiles($input) === []) {
			$output->writeln('No added or specified files. Skipping..');
			return 0;
		}
		Utils\FileSystem::createDir($this->tempDir);
		$this->dropDatabases();
		$masterSqlFile = $this->executeMaster($input, $output);
		$output->writeln('');
		$newSqlFile = $this->executeHead($output);
		$output->writeln('');
		$this->dropDatabases();
		return $this->result($output, $masterSqlFile, $newSqlFile);
	}


	private function executeHead(Output\OutputInterface $output): string
	{
		$output->writeln('<info>HEAD</info>');
		$newSqlFile = $this->tempDir . DIRECTORY_SEPARATOR . self::TEMP_HEAD;
		$this->importSqlStructureHead($output);
		$this->exportStructure($output, $newSqlFile, $this->headConnectionConfig());
		return $newSqlFile;
	}


	private function executeMaster(Input\InputInterface $input, Output\OutputInterface $output): string
	{
		$output->writeln('<info>master</info>');
		$masterSqlFile = $this->importSqlStructureFromMaster($input, $output);
		$this->importMigrationSql($input, $output);
		$this->exportStructure($output, $masterSqlFile, $this->masterConnectionConfig());
		return $masterSqlFile;
	}


	private function dropDatabases(): void
	{
		$this->psql->drop(self::HEAD_DB);
		$this->psql->drop(self::MASTER_DB);
	}


	private function commitId(Input\InputInterface $input): string
	{
		/** @var string $commitId */
		$commitId = $input->getOption('commit-id');
		return $commitId ?: $this->git->getCommonCommitId();
	}


	private function projectRelative(string $dir): string
	{
		return str_replace($this->git->fileAbsolutePath(''), '', (string) realpath($dir));
	}


	private function findFiles(Input\InputInterface $input): array
	{
		return $this->memoize(__FUNCTION__, function () use ($input): array {
			$files = (array) $input->getArgument('files');
			return $files ?: array_map([$this->git, 'fileAbsolutePath'], $this->git->getAddedSqlFiles($this->projectRelative($this->migrationsDir)));
		});
	}


	private function masterConnectionConfig(): PsqlCli\Config
	{
		return $this->memoize(__METHOD__, function () {
			return $this->psql->create(self::MASTER_DB);
		});
	}


	private function headConnectionConfig(): PsqlCli\Config
	{
		return $this->memoize(__METHOD__, function () {
			return $this->psql->create(self::HEAD_DB);
		});
	}


	private function importSqlStructureFromMaster(Input\InputInterface $input, Output\OutputInterface $output): string
	{
		$commitId = $this->commitId($input);
		$output->writeln(sprintf('Used commit id: %s', $commitId));

		$command = $this->git->show(sprintf('%s:%s', $commitId, $this->projectRelative($this->structureFilename)));
		$oldDatabaseStructure = $this->tempDir . DIRECTORY_SEPARATOR . self::MASTER_DB;
		if (@file_put_contents($oldDatabaseStructure, $command) === FALSE) {
			throw new \RuntimeException(sprintf('Can not write content to file %s', $oldDatabaseStructure));
		}
		$output->writeln(sprintf('Import database structure from master: %s', $oldDatabaseStructure));
		$this->psql->sql($oldDatabaseStructure, $this->masterConnectionConfig());
		return $oldDatabaseStructure;
	}


	private static function ignoredDml(string $file): array
	{
		$content = @file_get_contents($file);
		if ($content === FALSE) {
			throw new \RuntimeException(sprintf('Can not read from file %s', $file));
		}
		$dml = [];
		$clear = preg_replace_callback('/^(?:INSERT|SELECT|DELETE|UPDATE|WITH) .+;/sUm', static function (array $find) use (&$dml): string {
			$dml[] = $find[0];
			return '';
		}, $content);
		return ['SQL' => $clear, 'DML' => $dml];
	}


	private function importMigrationSql(Input\InputInterface $input, Output\OutputInterface $output): void
	{
		$sql = '';
		foreach ($this->findFiles($input) as $file) {
			['SQL' => $sql, 'DML' => $dml] = self::ignoredDml($file);

			$output->writeln(sprintf('Used file%s: %s', $dml === [] ? '' : ' (SQL was removed)', $file));
			$output->writeln(sprintf('<info>%s</info>', implode(PHP_EOL . PHP_EOL, $dml)), Output\OutputInterface::VERBOSITY_VERBOSE);
		}

		if ($sql !== '') {
			$migrationFile = $this->tempDir . DIRECTORY_SEPARATOR . self::TEMP_MIGRATION;
			if (@file_put_contents($migrationFile, $sql) === FALSE) {
				throw new \RuntimeException(sprintf('Can not write to %s', $migrationFile));
			}
			$this->psql->sql($migrationFile, $this->masterConnectionConfig());
		}
	}


	private function exportStructure(Output\OutputInterface $output, string $file, PsqlCli\Config $config): void
	{
		$output->writeln(sprintf('Export: %s', $file));
		$this->psql->dumpDatabase($file, $config);
	}


	private function importSqlStructureHead(Output\OutputInterface $output): void
	{
		$output->writeln(sprintf('Import %s', $this->structureFilename));
		$this->psql->sql($this->structureFilename, $this->headConnectionConfig());
	}


	private function result(Output\OutputInterface $output, string $oldSqlFile, string $newSqlFile): int
	{
		$diff = sprintf('diff -C 5 "%s" "%s"', $newSqlFile, $oldSqlFile);
		exec($diff, $execOut);
		if ($execOut) {
			$output->writeln('<error>SQL structure does not match</error>');
			$output->writeln(sprintf('<comment>Differences (%s):</comment>', $diff));
			$output->writeln($execOut);
			return 1;
		}
		$output->writeln('<info>[OK] SQL structure matches in this branch.</info>');
		return 0;
	}

}
