<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;

class Stp extends MfStrategies
{
    public $strategyDisplayName = 'STP';

    public $strategyDescription = 'Perform STP strategy on a portfolio';

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