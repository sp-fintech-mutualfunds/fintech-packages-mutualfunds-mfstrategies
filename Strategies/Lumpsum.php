<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;

class Lumpsum extends MfStrategies
{
    public $strategyDisplayName = 'Lumpsum';

    public $strategyDescription = 'Perform lumpsum strategy on a portfolio';

    public $strategyArgs = [];

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function run($portfolio)
    {
        trace([$portfolio]);
    }

    protected function getStategyArgs()
    {
        return [];
    }
}