<?php

    namespace OpCoding\Courier;
    
    
    use PHPCI\Builder;
    use PHPCI\Model\Build;
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
         * Courier constructor.
         *
         * @param Builder $phpci
         * @param Build   $build
         * @param array   $options
         */public function __construct(Builder $phpci, Build $build, array $options = [])
        {
            $this->phpci     = $phpci;
            $this->build     = $build;
            $this->options   = $options;
        }

        /**
         * @return bool
         * @throws Exception
         */
        public function execute()
        {
            $this->phpci->logExecOutput();
            $options = $this->options;
            $tarballPath = $this->generateTarball();

            $user = !empty($options['operator']) ? $options['operator'] : 'courier';


            // dump key file
            $this->keyFile = $this->writeSshKey($this->build->getBuildPath());

            $environment = isset($options['env']) ? $options['env'] : $this->guessEnv();
            if(!empty($options['targets'])) {

                $servers = $options['targets'];
                if(!isset($servers[$environment])){
                    throw new Exception('No target is defined for current environment (' . $environment . ')');
                }

                $remotePath = null;

                $deployed = true;

                foreach($servers[$environment] as $alias => $data)
                {
                    $remotePath = !empty($data['path']) ? $data['path'] : $remotePath;
                    $host = $data['host'];

                    if(is_null($remotePath)) {
                        throw new Exception('No remote path specified for target ' . $alias);
                    }
                    
                    if(!$this->pushTarball($alias, $host, $user, $tarballPath, $remotePath))
                    {
                         $deployed = false;
                        break;
                    }

                }

                // interrupt if any issue occured while deploying code
                if($deployed == false)
                {
                    throw new Exception(sprintf('Code has not been deployed on at least one server (%s)', $alias));
                }

                // handle storage
                $storageFolders = !empty($options['storage']) ? $options['storage'] : [];
                if($storageFolders)
                {
                    $remoteStoragePath = $this->getRemoteStorageFolder($remotePath);
                    if (!$this->slogin($user, $host, '[[ -e ' . $remoteStoragePath . ' ]] || mkdir ' . $remoteStoragePath))
                    {
                        throw new Exception(sprintf('Cannot create storage directory'));
                    }
                    foreach ($storageFolders as $storageFolder)
                    {
                        $this->activateStorage($host, $user, $remotePath,  $storageFolder);
                    }
                }

            }

            reset($servers);

            // activate last build

            $remotePath = null;

            $deployed = true;

            foreach ($servers[$environment] as $alias => $data)
            {
                $remotePath = !empty($data['path']) ? $data['path'] : $remotePath;
                $host       = $data['host'];

                $this->activateLastBuild($user, $host, $remotePath);

            }
            return true;
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
        
        
            $filename =  $build->getId() . '-' . $build->getCommitId() . '.tar.gz';
            
            $curdir = getcwd();
            chdir($this->phpci->buildPath);
        
            $cmd = 'tar cfz "%s/%s" ./*';
            
            $success = $this->phpci->executeCommand($cmd, $path, $filename);
        
            chdir($curdir);
        
            if($success)
            {
                return realpath($path . '/' . $filename);
            }
            else {
                throw new Exception("Unknown error while dumping the tarball.");
            }
            
        }

        /**
         * @param $remotePath
         *
         * @return string
         */protected function getRemoteBuildFolder($remotePath)
        {
            $buildReference = $this->getBuildReference();

            return rtrim($remotePath, '/') . '/builds/' . $buildReference . '/';

        }

        /**
         * @param $remotePath
         *
         * @return string
         */protected function getRemoteStorageFolder($remotePath)
        {
            return rtrim($remotePath, '/') . '/storage/';
        }

        /**
         * @return string
         */
        protected function getBuildReference()
        {
            return $buildReference = $this->build->getId() . '-' . $this->build->getCommitId();
        }

        /**
         * @param $host
         * @param $user
         * @param $remotePath
         * @param $storageFolder
         *
         * @throws Exception
         */public function activateStorage($host, $user, $remotePath, $storageFolder)
        {

            $remoteStorageFolder = $this->getRemoteStorageFolder($remotePath);

            if (!$this->slogin($user, $host, '[[ -e ' . $remoteStorageFolder . '/' . $storageFolder . ' ]] || mkdir ' . $remoteStorageFolder . '/' . $storageFolder))
            {
                throw new Exception(sprintf('Cannot create "%s" storage directory in "%s"', $storageFolder, $remoteStorageFolder));
            }

            if(!$this->slogin($user, $host, 'ln -s ' . $remoteStorageFolder . '/' . $storageFolder . ' ' . $this->getRemoteBuildFolder($remotePath)));

        }

        /**
         * @param $alias
         * @param $host
         * @param $user
         * @param $tarballPath
         * @param $remotePath
         *
         * @return bool
         * @throws Exception
         */public function pushTarball($alias, $host, $user, $tarballPath, $remotePath)
        {
            $this->phpci->log('Pushing tarball to ' . $alias);

            // append 'builds' to remote path
            $remotePath = rtrim($remotePath, '/') . '/builds/';

            if (!$this->slogin($user, $host, '[[ -e ' . $remotePath . ' ]] || mkdir ' . $remotePath))
            {
                throw new Exception(sprintf('Cannot create builds destination directory'));
            }

            // copy tarball
            if(!$this->scp($tarballPath, $remotePath, $user, $host))
            {
                throw new Exception(sprintf('Error while copying "%s" to "%s"', $tarballPath, $remotePath));
            }

            // deflate it
            $buildReference  = $this->getBuildReference();
            $destinationPath = rtrim($remotePath, '/') . '/' . $buildReference;


            if ($this->slogin($user, $host, 'mkdir ' . $destinationPath))
            {
                if (!$this->slogin($user, $host, "tar -xf " . $remotePath . '/' . basename($tarballPath) . ' -C ' . $destinationPath))
                {
                    $this->slogin($user, $host, "rm -rf $destinationPath");
                    throw new Exception(sprintf('Error while deflating tarball "%s" to "%s"', $tarballPath, $destinationPath));
                }
            } else
            {
                throw new Exception(sprintf('Cannot create destination folder "%s"', $destinationPath));
            }

            return true;

        }

        /**
         * @return mixed|string
         */
        protected function guessEnv()
        {
            $mapping = !empty($this->options['env-mapping']) ? (array) $this->options['env-mapping'] : ['*' => 'development'];
            $branch = $this->build->getBranch();
            foreach($mapping as $mask => $env)
            {
                if($mask == $branch ||$mask == '*')
                {
                    return $env;
                }
            }

            // default
            return 'development';
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
         * Create an SSH wrapper script for Svn to use, to disable host key checking, etc.
         *
         * @param $user
         * @param $host
         * @param $command
         *
         * @return string
         *
         */
        protected function slogin($user, $host, $command)
        {

            $sshFlags = '-o CheckHostIP=no -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o PasswordAuthentication=no';

            $cmd = "slogin {$sshFlags} -o IdentityFile={$this->keyFile} $user@$host " . '"' . $command . '"';

            return $this->phpci->executeCommand($cmd);
        

        }
    
        /**
         * Create an SSH wrapper script for Svn to use, to disable host key checking, etc.
         *
         * @param $from
         * @param $to
         * @param $user
         * @param $host
         *
         * @return string
         *
         */
        protected function scp($from, $to, $user, $host)
        {
            $sshFlags = '-o CheckHostIP=no -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o PasswordAuthentication=no';

            $cmd = "scp {$sshFlags} -o IdentityFile={$this->keyFile} " . escapeshellarg($from) . ' ' . escapeshellarg($user .'@' . $host . ':' . $to);

            return $this->phpci->executeCommand($cmd);
        }

        /**
         * @param $remotePath
         */
        protected function activateLastBuild($user, $host, $remotePath)
        {

            $lastBuildPath = $this->getRemoteBuildFolder($remotePath);

            if($this->slogin($user, $host, '[[ -e ' . $remotePath . '/active ]] && rm ' . $remotePath . '/active; ln -s ' . $lastBuildPath . ' ' . $remotePath . '/active'))
            {
                $this->phpci->log('Active link set to latest build on ' . $host);
            }
            else {
                throw new Exception('An error occured while activating last build');
            }

        }

    }
