#!/bin/env php
<?php

define('SERVER_ROOT', '/srv/www/');
define('DEFAULT_SKEL_DIR', SERVER_ROOT . '.skel/');
define('CONFIG_DIR', SERVER_ROOT . '.conf/');

define('DEFAULT_HOST', '*');
define('DEFAULT_PORT', '80');

function recurse_copy($src,$dst) 
{ 
    $dir = opendir($src);
	
    @mkdir($dst);
	
    while (false !== ($file = readdir($dir)))
	{
        if (($file != '.') && ($file != '..'))
		{
            if (is_dir($src . '/' . $file))
			{
                recurse_copy($src . '/' . $file,$dst . '/' . $file);
            }
            else
			{
                copy($src . '/' . $file,$dst . '/' . $file);
            }
        }
    }
	
    closedir($dir);
} 

class VHostManager
{
	// Script Name(ex: vhost-manager.php)
	var $version;
	var $script_name;
	
	// Action to be executed
	var $action;
	var $action_args;
	
	// Surpress logo?
	var $quiet;
	
	// Force execute the action?
	var $force;
	
	// General options
	var $host;
	var $port;
	var $setdefault;
	var $skel;

	function __construct()
	{
		$this->script_name = 'vhost-manager.php';
		$this->action = 'none';
		$this->action_args = array();
		$this->quiet = false;
		$this->force = false;
		$this->host = DEFAULT_HOST;
		$this->port = DEFAULT_PORT;
		$this->skel = DEFAULT_SKEL_DIR;
	}

	// Parse arguments and call apropriate action
	function run($args)
	{
		$this->parse_options($args);
	
		if (!$this->quiet && $this->action != 'version')
		{
			$this->version_action(array());
			echo "\n";
		}
	
		if ($this->action == 'none' || !method_exists($this, "{$this->action}_action"))
		{
			$this->usage_action(array());
		}
		else
		{
			call_user_method("{$this->action}_action", $this, $this->action_args);
		}
	}
	
	// Argument parser
	function parse_options($args)
	{
		$argslen = count($args);
		
		for ($i = 0; $i < $argslen; $i++)
		{
			$arg = $args[$i];
			
			if ($arg[0] == '-')
			{
				switch ($arg)
				{
					case '--host':
					case '-h':
						$this->host = $args[$i + 1];
						break;
					case '--port':
					case '-p':
						$this->port = $args[$i + 1];
						break;
					case '--force':
					case '-f':
						$this->force = true;
						break;
					case '--default':
					case '-d':
						$this->setdefault = true;
						break;
					case '--quiet':
					case '-q':
						$this->quiet = true;
						break;
					case '--help':
					case '-h':
						$this->action = 'usage';
						break;
					case '--version':
					case '-V':
						$this->action = 'version';
						break;
				}
			}
			else
			{
				$i++;
				$this->action = $arg;
				break;
			}
			
			if ($this->action != 'none')
				break;
		}
		
		if ($i < $argslen)
			$this->action_args = array_slice($args, $i);
	}
	
	// Installs itself on Apache
	function setup_action_help()
	{
		echo "wrong usage, use '{$this->script_name} --help' for help.\n";
	}
	
	function setup_action($args)
	{
		if (count($args) < 1)
		{
			$this->setup_action_help();
			return;
		}
		
		$apacheconf = $args[0];
		
		if (!file_exists($apacheconf))
		{
			fprintf(STDERR, "error: could not open apache config file\n");
			return;
		}
		
		$CONFIG_DIR = CONFIG_DIR;
		
		$data = file_get_contents($apacheconf);
		$data .= <<<END

####### BEGIN VHOST-MANAGER CONFIG #######
Include $CONFIG_DIR*.conf
#######  END VHOST-MANAGER CONFIG  #######

END;
		file_put_contents($apacheconf, $data);
		
		$this->addport_sub(DEFAULT_HOST, DEFAULT_PORT, true, true);
		echo "done.";
	}
	
	// Add a vhost port
	function addport_action_help()
	{
		echo "wrong usage, use '{$this->script_name} --help' for help.\n";
	}
	
	function addport_sub($host, $port, $quiet, $force)
	{
		$nhost = $host;
		$nhost = str_replace(".", "_", $nhost);
		$nhost = str_replace("*", "all", $nhost);
		
		$fname = CONFIG_DIR . "0_addport_{$nhost}_{$port}.conf";
		
		if (!$force && file_exists($fname))
		{
			if (!$quiet)
			{
				echo "host port already exists, use --force to overwrite.\n";
			}
		
			return;
		}
		
		$data = <<<END

# addport settings for $host:$port
Listen $host:$port
NameVirtualHost $host:$port

END;
		file_put_contents($fname, $data);
		
		if (!$quiet)
		{
			echo "done.\n";
		}
	}
	
	function addport_action($args)
	{
		$this->addport_sub($this->host, $this->port, false, $this->force);
	}
	
	// Delete a vhost port
	function delport_action_help()
	{
		echo "wrong usage, use '{$this->script_name} --help' for help.\n";
	}
	
	function delport_action($args)
	{
		$nhost = $this->host;
		$nhost = str_replace(".", "_", $nhost);
		$nhost = str_replace("*", "all", $nhost);
		
		$fname = CONFIG_DIR . "0_addport_{$nhost}_{$this->port}.conf";
		
		if ($this->host == DEFAULT_HOST && $this->port == DEFAULT_PORT && !$this->force)
		{
			echo "you can't remove the default vhost, use --force to override.\n";
			return;
		}
		
		unlink($fname);
		echo "done.\n";
	}
	
	// Add a vhost
	function addhost_action_help()
	{
		echo "wrong usage, use '{$this->script_name} --help' for help.\n";
	}
	
	function addhost_action($args)
	{
		if (count($args) < 1)
		{
			addhost_action_help();
			return;
		}
	
		$shost = $this->host;
		$shost = str_replace(".", "_", $shost);
		$shost = str_replace("*", "all", $shost);
		
		$nhost = $args[0];
		
		$compl = "";
		$complf = "";
		$compld = "";
		if ($this->host != DEFAULT_HOST)
			$compl .= "$shost";
		if ($this->port != DEFAULT_PORT)
			$compl .= $compl != "" ? '_' : "" . "{$this->port}";
			
		if ($compl != "")
		{
			$complf = "_$compl";
			$compld = "$compl_";
		}
			
		if ($this->setdefault)
			$fname = CONFIG_DIR . "1_vhost{$complf}_default_{$this->port}.conf";
		else
			$fname = CONFIG_DIR . "2_vhost{$complf}_{$nhost}_{$this->port}.conf";
			
		$dname = SERVER_ROOT . "{$compld}{$nhost}/";
		
		if (file_exists($fname) && !$this->force)
		{
			echo "vhost already exists, use --force to overwrite.\n";
			return;
		}
		
		if (file_exists($dname) && !$this->force)
		{
			echo "vhost directory already, use --force to overwrite.\n";
		}
		else
		{
			recurse_copy($this->skel, $dname);
			unlink($dname . '.vhost.conf');
		}
		
		$data = file_get_contents($this->skel . '.vhost.conf');
		$data = str_replace('{SERVER_ROOT}', SERVER_ROOT, $data);
		$data = str_replace('{CONFIG_DIR}', CONFIG_DIR, $data);
		$data = str_replace('{DEFAULT_HOST}', DEFAULT_HOST, $data);
		$data = str_replace('{DEFAULT_PORT}', DEFAULT_PORT, $data);
		$data = str_replace('{DATA_ROOT}', $dname, $data);
		$data = str_replace('{HOST}', $this->host, $data);
		$data = str_replace('{PORT}', $this->port, $data);
		$data = str_replace('{VNAME}', $args[0], $data);
		$data = str_replace('{ALIAS}', count($args) > 1 ? implode(' ', array_slice($args, 1)) : "", $data);
		file_put_contents($fname, $data);
		
		echo "done.\n";
	}
	
	// Remove a vhost
	function delhost_action_help()
	{
		echo "wrong usage, use '{$this->script_name} --help' for help.\n";
	}
	
	function delhost_action()
	{
		if (count($args) < 1)
		{
			addhost_action_help();
			return;
		}
	
		$shost = $this->host;
		$shost = str_replace(".", "_", $nhost);
		$shost = str_replace("*", "all", $nhost);
		
		$nhost = $args[0];
		
		$compl = "";
		$complf = "";
		if ($this->host != DEFAULT_HOST)
			$compl .= "$shost";
		if ($this->port != DEFAULT_PORT)
			$compl .= $compl != "" ? '_' : "" . "{$this->port}";
			
		if ($compl != "")
		{
			$complf = "_$compl";
		}
			
		$fname = CONFIG_DIR . "2_vhost{$complf}_{$nhost}_{$this->port}.conf";
		
		if (file_exists($fname))
		{
			echo "vhost does not exists.\n";
			return;
		}
		
		unlink($fname);
		
		echo "done.";
	}
	
	// Show help banner
	function usage_action($args)
	{
		echo "usage:\n";
		echo "\t{$this->script_name} [options] [action [action-args]]\n";
		echo "\n";
		echo "avaiable options:\n";
		echo "\t-h --host: Specify the host\n";
		echo "\t-p --port: Specify the port\n";
		echo "\t-s --skel: Specify skeleton dir\n";
		echo "\t-d --default: Create the vhost as default\n";
		echo "\t-q --quiet: Surpress logo\n";
		echo "\t-h --help: Show this message\n";
		echo "\t-V --version: Show script version\n";
		echo "\n";
		echo "avaiable actions:\n";
		echo "\thelp <command>: Show help message for <command>.\n";
		echo "\tsetup <apache config>: Install this system on <apache config>.\n";
		echo "\taddport <host> <port>: Add a new vhost host:port on the server.\n";
		echo "\tdelport <host> <port>: Remove a vhost host:port on the server.\n";
		echo "\taddhost <name> [alias...]: Install a new vhost.\n";
		echo "\tdelhost <name>: Remove a vhost(only config).\n";
	}
	
	// Show script version
	function version_action($args)
	{
		echo "vhost-manager by greenboxal - v{$this->version}\n";
	}
}

$instance = new VHostManager();
$instance->version = '1.0';
$instance->script_name = $argv[0];
$instance->run(array_slice($argv, 1));
