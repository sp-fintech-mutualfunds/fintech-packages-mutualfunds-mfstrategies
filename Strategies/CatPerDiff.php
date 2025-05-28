<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;

class CatPerDiff extends MfStrategies
{
    public $strategyDisplayName = 'Categories Percentage Difference Threshold';

    public $strategyDescription = 'Balance categories investment once a certain threshold is achieved.';

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