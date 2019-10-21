<?php declare(strict_types=1);

namespace PmgDev\Migrations\Command;

use Nette\Utils;
use PmgDev\Migrations\Config;
use PmgDev\Migrations\Exceptions\InvalidOptionException;
use PmgDev\Migrations\Factory;
use Symfony\Component\Console\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;
use Symfony\Component\Console\Question;
use PmgDev\GitCli;

final class CreateMigration extends Command\Command
{
	/** @var string */
	private $migrationsDir;

	/** @var GitCli\Command */
	private $git;


	public function __construct()
	{
		parent::__construct('create');
	}


	protected function configure(): void
	{
		$this->setDescription('Create new SQL migration script')
			->addOption('config', NULL, Input\InputOption::VALUE_REQUIRED)
			->addArgument('name', NULL, 'SQL migration script name');
	}


	public function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		/** @var string $configOption */
		$configOption = $input->getOption('config');
		$factory = new Factory(new Config($configOption, ['projectDir', 'migrationsDir']));
		['migrationsDir' => $this->migrationsDir] = $factory->getParameters();
		$this->git = $factory->createGit();

		/** @var string $name */
		$name = $input->getArgument('name');
		$name = basename($name ?: $this->generatedName($input, $output), '.sql');
		$dir = $this->migrationsDir . DIRECTORY_SEPARATOR . date('Y');
		Utils\FileSystem::createDir($dir, 0755);
		$filename = sprintf('%s-%s.sql', date('Y-m-d'), $name);
		$file = (string) realpath($dir) . DIRECTORY_SEPARATOR . $filename;
		if (is_file($file)) {
			$output->writeln(sprintf('<error>File "%s" already exists.</error>', $file));
			return 1;
		}
		file_put_contents(
			$file,
			<<<SQL
			-- ###### DEPLOY SCRIPT $filename ######
			SELECT system.deploy_script('$filename');



			-- ###### DEPLOY SCRIPT $filename ######

			SQL,
		);
		$output->writeln(sprintf('<info>[OK] File "%s" was created.</info>', $file));
		return 0;
	}


	private function generatedName(Input\InputInterface $input, Output\OutputInterface $output): string
	{
		$name = $this->branchName();
		$helper = $this->getHelper('question');
		$question = new Question\ConfirmationQuestion(sprintf('Do you want to use GIT branch name "%s"? [Y/n] ', $name), TRUE);
		if (!$helper->ask($input, $output, $question)) {
			throw new InvalidOptionException('You must specify migration name');
		}
		return $name;
	}


	private function branchName(): string
	{
		$name = $this->git->getBranchName();
		$names = explode('/', $name);
		return (string) end($names);
	}

}
