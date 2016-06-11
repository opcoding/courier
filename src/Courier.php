<?php

    
    namespace OpCoding\Courier;
    
    
    use PHPCI\Builder;
    use PHPCI\Model\Build;
    use PHPCI\Plugin;

    class Builder // implements Plugin
    {

        /**
         * @var string
         */
        protected $directory;


        /**
         * @var Builder
         */
        protected $phpci;

        /**
         * @var Build
         */
        protected $build;

        public function __construct(Builder $phpci, Build $build, array $options = [])
        {
            $path            = $phpci->buildPath;
            $this->phpci     = $phpci;
            $this->build     = $build;
            $this->directory = $path;
        }

        public function execute()
        {
            var_dump($this->phpci);
        }
    }
