<?php declare(strict_types=1);

namespace PmgDev\Migrations\Command;

use Nette\Utils;
use PmgDev\Migrations\Config;
use PmgDev\Migrations\Factory;
use Symfony\Component\Console\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use PmgDev\PsqlCli;

final class ListMigrations extends Command\Command
{
	/** @var string */
	private $migrationsDir;

	/** @var PsqlCli\Command */
	private $psql;


	public function __construct()
	{
		parent::__construct('list');
	}


	protected function configure(): void
	{
		$this->setDescription('List migrations needed to import')
			->addOption('config', NULL, Input\InputOption::VALUE_REQUIRED);
	}


	public function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		/** @var string $configOption */
		$configOption = $input->getOption('config');
		$config = new Config($configOption);
		$factory = new Factory($config);
		['migrationsDir' => $this->migrationsDir] = $config->parameters();
		$this->psql = $factory->createPsql();

		$files = $this->getFiles();
		if ($files === []) {
			$output->writeln('<info>[OK] Your database is up to date.</info>');
		} else {
			$output->writeln(sprintf('<comment>Please import these files (%d) into your database:</comment>', count($files)));
			foreach ($files as $file => $filename) {
				$output->writeln((string) $file);
			}
		}

		return 0;
	}


	/**
	 * @return string[]
	 */
	private function getFiles(): array
	{
		$files = [];
		/** @var \SplFileInfo $file */
		foreach (Utils\Finder::findFiles('*.sql')->from($this->migrationsDir) as $file) {
			$files[$file->getRealPath()] = $file->getFilename();
		}
		[, , $record] = $this->psql->sql(sprintf("SELECT system.list_deployed_scripts('%s')", implode(',', $files)));
		return array_intersect($files, explode(',', trim($record)));
	}

}