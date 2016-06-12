<?php

    namespace OpCoding\Courier;
    
    
    use b8\Store\Factory;
    use PHPCI\Builder;
    use PHPCI\Model\Build;
    use PHPCI\Model\BuildError;
    use PHPCI\Plugin;

    /**
     * Class Courier
     *
     * @package OpCoding\Courier
     */
    class Courier implements Plugin
    {
        /**
         * @var Builder
         */
        protected $phpci;

        /**
         * @var Build
         */
        protected $build;

        /**
         * @var array
         */
        protected $options;

        /**
         * @var string
         */
        protected $keyFile;

        /**
         * @var string
         */
        protected $username;

        /**
         * @var string
         */
        protected $tarballPath;


        /**
         * Courier constructor.
         *
         * @param Builder $phpci
         * @param Build   $build
         * @param array   $options
         */
        public function __construct(Builder $phpci, Build $build, array $options = [])
        {
            $this->phpci   = $phpci;
            $this->build   = $build;
            $this->options = $options;
        }

        /**
         * @return bool
         * @throws Exception
         */
        public function execute()
        {
            try
            {
                $this->phpci->logExecOutput();
                $options           = $this->options;
                $tarballPath       = $this->generateTarball();
                $this->tarballPath = $tarballPath;


                $this->username = !empty($options['operator']) ? $options['operator'] : 'courier';

                // dump key file
                $this->keyFile = $this->writeSshKey($this->build->getBuildPath());

                $environment = isset($options['env']) ? $options['env'] : $this->guessEnv();
                if (!empty($options['targets']))
                {

                    $servers = $options['targets'];
                    if (!isset($servers[$environment]))
                    {
                        throw new Exception('No target is defined for current environment (' . $environment . ')');
                    }

                    $remotePath = null;

                    $deployed = true;

                    foreach ($servers[$environment] as $alias => $data)
                    {
                        $remotePath = !empty($data['path']) ? $data['path'] : $remotePath;
                        $host       = $data['host'];

                        if (is_null($remotePath))
                        {
                            throw new Exception('No remote path specified for target ' . $alias);
                        }

                        if (!$this->pushTarball($alias, $host, $remotePath))
                        {
                            $deployed = false;
                            break;
                        }

                        // handle storage
                        $storageFolders = !empty($options['storage']) ? $options['storage'] : [];
                        if ($storageFolders)
                        {
                            $remoteStoragePath = $this->getRemoteStorageFolder($remotePath);
                            if (!$this->slogin($host, '[[ -e ' . $remoteStoragePath . ' ]] || mkdir ' . $remoteStoragePath))
                            {
                                throw new Exception(sprintf('Cannot create storage directory'));
                            }

                            foreach ($storageFolders as $storageFolder => $link)
                            {
                                $this->activateStorage($host, $remotePath, $storageFolder, $link);
                            }
                        }

                        $this->runHook('pre-activation', $host, $remotePath);

                    }

                    // interrupt if any issue occured while deploying code
                    if ($deployed == false)
                    {
                        throw new Exception(sprintf('Code has not been deployed on at least one server (%s)', $alias));
                    }

                }

                reset($servers);

                // activate last build
                $remotePath = null;

                foreach ($servers[$environment] as $alias => $data)
                {
                    $remotePath = !empty($data['path']) ? $data['path'] : $remotePath;
                    $host       = $data['host'];

                    $this->activateLastBuild($host, $remotePath);
                    $this->runHook('post-activation', $host, $remotePath);
                    $this->cleanBuilds($host, $remotePath);

                }
                $this->build->storeMeta('deployed', true);
                return true;
            } catch (\Exception $e)
            {
                // something went wrong...
                $this->build->setStatus(Build::STATUS_FAILED);
                $this->build->reportError($this->phpci, 'courier', $e->getMessage(), BuildError::SEVERITY_HIGH, $e->getFile(), $e->getLine());
                Factory::getStore('Build')->save($this->build);
                throw $e;
            }
        }

        /**
         * Generate tarball from sources
         *
         * @return bool|string
         * @throws Exception
         */
        public function generateTarball()
        {
            $path  = $this->phpci->buildPath;
            $build = $this->build;


            $filename = $build->getId() . '-' . $build->getCommitId() . '.tar.gz';
            
            $curdir = getcwd();
            chdir($this->phpci->buildPath);

            $cmd = 'tar cfz "%s/%s" ./*';
            
            $success = $this->phpci->executeCommand($cmd, $path, $filename);

            chdir($curdir);

            if ($success)
            {
                return realpath($path . '/' . $filename);
            }
            else
            {
                throw new Exception("Unknown error while dumping the tarball.");
            }
            
        }

        /**
         * Create an SSH key file on disk for this build.
         *
         * @param $destination
         *
         * @return string
         */
        protected function writeSshKey($destination)
        {
            $keyPath = dirname($destination . '/temp');

            $keyFile = $keyPath . '/.key';

            // Write the contents of this project's svn key to the file:
            file_put_contents($keyFile, $this->build->getProject()->getSshPrivateKey());
            chmod($keyFile, 0600);

            // Return the filename:
            return $keyFile;
        }

        /**
         * @return mixed|string
         */
        protected function guessEnv()
        {
            $mapping = !empty($this->options['env-mapping']) ? (array) $this->options['env-mapping'] : ['*' => 'development'];
            $branch  = $this->build->getBranch();
            foreach ($mapping as $mask => $env)
            {
                if ($mask == $branch || $mask == '*')
                {
                    return $env;
                }
            }

            // default
            return 'development';
        }

        /**
         * @param $alias
         * @param $host
         * @param $remotePath
         *
         * @return bool
         * @throws Exception
         */
        public function pushTarball($alias, $host, $remotePath)
        {
            $tarballPath = $this->tarballPath;

            $this->phpci->log('Pushing tarball to ' . $alias);

            // append 'builds' to remote path
            $remotePath = rtrim($remotePath, '/') . '/builds/';

            if (!$this->slogin($host, '[[ -e ' . $remotePath . ' ]] || mkdir ' . $remotePath))
            {
                throw new Exception(sprintf('Cannot create builds destination directory'));
            }

            // copy tarball
            if (!$this->scp($host, $tarballPath, $remotePath))
            {
                throw new Exception(sprintf('Error while copying "%s" to "%s"', $tarballPath, $remotePath));
            }

            // deflate it
            $buildReference  = $this->getBuildReference();
            $destinationPath = rtrim($remotePath, '/') . '/' . $buildReference;


            if ($this->slogin($host, 'mkdir ' . $destinationPath))
            {
                if (!$this->slogin($host, "tar -xf " . $remotePath . '/' . basename($tarballPath) . ' -C ' . $destinationPath))
                {
                    $this->slogin($host, "rm -rf $destinationPath");
                    throw new Exception(sprintf('Error while deflating tarball "%s" to "%s"', $tarballPath, $destinationPath));
                }
            }
            else
            {
                throw new Exception(sprintf('Cannot create destination folder "%s"', $destinationPath));
            }

            return true;

        }

        /**
         * Create an SSH wrapper script for Svn to use, to disable host key checking, etc.
         *
         * @param $host
         * @param $command
         *
         * @return string
         * @throws Exception
         */
        protected function slogin($host, $command)
        {
            $user = $this->username;

            if (!$user)
            {
                throw new Exception('No operator has been set (remote username)');
            }

            $sshFlags = '-o CheckHostIP=no -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o PasswordAuthentication=no';

            $cmd = "slogin {$sshFlags} -o IdentityFile={$this->keyFile} $user@$host " . '"' . $command . '"';

            return $this->phpci->executeCommand($cmd);


        }

        /**
         * Create an SSH wrapper script for Svn to use, to disable host key checking, etc.
         *
         * @param $host
         *
         * @param $from
         * @param $to
         *
         * @return string
         * @throws Exception
         */
        protected function scp($host, $from, $to)
        {
            $user = $this->username;

            if (!$user)
            {
                throw new Exception('No operator has been set (remote username)');
            }

            $sshFlags = '-o CheckHostIP=no -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o PasswordAuthentication=no';

            $cmd = "scp {$sshFlags} -o IdentityFile={$this->keyFile} " . escapeshellarg($from) . ' ' . escapeshellarg($user . '@' . $host . ':' . $to);

            return $this->phpci->executeCommand($cmd);
        }

        /**
         * @return string
         */
        protected function getBuildReference()
        {
            return $buildReference = $this->build->getId() . '-' . $this->build->getCommitId();
        }

        /**
         * @param $remotePath
         *
         * @return string
         */
        protected function getRemoteStorageFolder($remotePath)
        {
            return rtrim($remotePath, '/') . '/storage/';
        }

        /**
         * @param $host
         * @param $remotePath
         * @param $storageFolder
         *
         * @throws Exception
         */
        public function activateStorage($host, $remotePath, $storageFolder, $relativePath)
        {
            $remoteStorageFolder = $this->getRemoteStorageFolder($remotePath);

            if (!$this->slogin($host, '[[ -e ' . $remoteStorageFolder . '/' . $storageFolder . ' ]] || mkdir ' . $remoteStorageFolder . '/' . $storageFolder))
            {
                throw new Exception(sprintf('Cannot create "%s" storage directory in "%s"', $storageFolder, $remoteStorageFolder));
            }

            $target = $this->getRemoteBuildFolder($remotePath) . rtrim($relativePath, '/') . '/';

            if (!$this->slogin($host, 'ln -s ' . rtrim($remoteStorageFolder, '/') . '/' . $storageFolder . ' ' . $target)) {
                $this->phpci->logFailure(sprintf('Failed activating "%s" storage folder on "%s"', $storageFolder, $host));
            } else {
                $this->phpci->log(sprintf('Successfully activated "%s" storage folder on "%s"', $storageFolder, $host));
            }

        }

        /**
         * @param $remotePath
         *
         * @return string
         */
        protected function getRemoteBuildFolder($remotePath)
        {
            $buildReference = $this->getBuildReference();

            return rtrim($remotePath, '/') . '/builds/' . $buildReference . '/';

        }

        /**
         * @param $host
         * @param $remotePath
         *
         * @throws Exception
         */
        protected function activateLastBuild($host, $remotePath)
        {

            $lastBuildPath = $this->getRemoteBuildFolder($remotePath);

            if ($this->slogin($host, '[[ -e ' . $remotePath . '/active ]] && rm ' . $remotePath . '/active; ln -s ' . $lastBuildPath . ' ' . $remotePath . '/active'))
            {
                $this->phpci->log('Active link set to latest build on ' . $host);
            }
            else
            {
                throw new Exception('An error occurred while activating last build');
            }

        }


        /**
         * @param $host
         * @param $remotePath
         */
        private function cleanBuilds($host, $remotePath)
        {

            $commands = [];

            $commands[] = 'cd ' . rtrim($remotePath, '/') . '/builds/';
            $commands[] = 'rm -f *.gz';
            $commands[] = 'ls -tp | grep \'/$\' | tail -n +4 | tr \'\n\' \'\0\' | xargs -0 rm -rf';

            $this->slogin($host, implode(';', $commands));
        }

        public function runHook($name, $host, $remotePath)
        {
            $hooksRelativePath = !empty($this->options['hooks']) ? $this->options['hooks'] : null;

            if(!$hooksRelativePath) return;

            $hookScript = rtrim($hooksRelativePath, '/') . '/' . $name . '.sh';

            $hookFullPath = rtrim($this->build->getBuildPath(), '/') . '/' . $hookScript;

            if(file_exists($hookFullPath))
            {
                $remoteHookPath = rtrim($remotePath, '/') . '/builds/' . $this->getBuildReference() . '/' . $hookScript;
                $commands = [];
                $commands[] = 'cd ' . $remotePath . '/builds/' . $this->getBuildReference();
                $commands[] = 'chmod +x ' . $remoteHookPath;
                $commands[] = $remoteHookPath;
                $result = $this->slogin($host, implode(';', $commands));
                if($result) $this->phpci->log(sprintf('Ran "%s" hook on "%s"', $name, $host));
                else {
                    $this->phpci->logFailure(sprintf('Failed running "%s" hook on "%s"', $name, $host));
                    $this->build->setStatus(Build::STATUS_FAILED);
                }
                return $result;
            }

            return;
        }
    }
