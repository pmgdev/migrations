<?php declare(strict_types=1);

namespace PmgDev\Migrations;

use PmgDev\PsqlCli;
use PmgDev\GitCli;

final class Factory
{
	/** @var Config */
	private $config;

	/** @var mixed[] */
	private $parameters;


	public function __construct(Config $config)
	{
		$this->config = $config;
		$this->parameters = $config->parameters();
	}


	public function createPsql(array $options = []): PsqlCli\Command
	{
		return new PsqlCli\Command(
			$this->parameters['sqlUtility'],
			$this->parameters['dumpUtility'],
			PsqlCli\Config::fromArray($options + $this->parameters['credentials']['main'])
		);
	}


	public function createGit(): GitCli\Command
	{
		return new GitCli\Command($this->parameters['projectDir']);
	}

}
