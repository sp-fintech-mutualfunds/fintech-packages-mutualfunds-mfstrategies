<?php

namespace Apps\Fintech\Packages\Mf\Strategies\Strategies;

use Apps\Fintech\Packages\Mf\Categories\MfCategories;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use Apps\Fintech\Packages\Mf\Strategies\MfStrategies;
use Apps\Fintech\Packages\Mf\Transactions\MfTransactions;

class CatPerDiff extends MfStrategies
{
    public $strategyDisplayName = 'Categories Percentage Difference Threshold';

    public $strategyDescription = 'Balance categories investment once a certain percentage difference threshold is achieved.';

    public $strategyArgs = [];

    public $transactions = [];

    public $transactionsCount = [];

    public $totalTransactionsCount = 0;

    protected $totalTransactionsAmounts = [];

    protected $portfolioPackage;

    protected $transactionPackage;

    protected $schemePackage;

    protected $categoriesPackage;

    protected $startEndDates;

    protected $portfolio;

    protected $schemes;

    public function init()
    {
        $this->strategyArgs = $this->getStategyArgs();

        parent::init();

        return $this;
    }

    public function processStrategyTransactionsByDate($data, $date)
    {
        if (!$this->categoriesPackage) {
            $this->categoriesPackage = $this->usePackage(MfCategories::class);
        }

        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);
        if ($this->helper->firstKey($this->transactions) === $date) {
            $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $data['portfolio_id']], true);
        }
        $this->portfolio = $this->portfolioPackage->getPortfolioById($data['portfolio_id']);
        $this->transactions['initial_transactions'] = $this->portfolio['transactions'];

        if (!$this->schemePackage) {
            $this->schemePackage = $this->usePackage(MfSchemes::class);
        }

        if (!isset($this->schemes[$data['first_scheme']])) {
            $scheme = $this->schemePackage->getMfTypeByAmfiCode((int) $data['first_scheme']);

            if (!$scheme) {
                $this->addResponse('Scheme with amfi code for first scheme not found', 1);

                return false;
            }

            $this->schemes[$data['first_scheme']] = $this->schemePackage->getSchemeById((int) $scheme['id']);
        }

        if (!isset($this->schemes[$data['second_scheme']])) {
            $scheme = $this->schemePackage->getMfTypeByAmfiCode((int) $data['second_scheme']);

            if (!$scheme) {
                $this->addResponse('Scheme with amfi code for first scheme not found', 1);

                return false;
            }

            $this->schemes[$data['second_scheme']] = $this->schemePackage->getSchemeById((int) $scheme['id']);
        }

        $firstSchemeValue =
            numberFormatPrecision(
                $this->schemes[$data['first_scheme']]['navs']['navs'][$date]['nav'] * $this->portfolio['investments'][$data['first_scheme']]['units'], 2
            );
        $secondSchemeValue =
            numberFormatPrecision(
                $this->schemes[$data['second_scheme']]['navs']['navs'][$date]['nav'] * $this->portfolio['investments'][$data['second_scheme']]['units'], 2
            );
        $categoryDiff = $this->categoriesPackage->calculateCategoriesPercentDiff($firstSchemeValue, $secondSchemeValue);

        $thresholdPercent = (float) $data['threshold_percent'];

        if ($categoryDiff > $thresholdPercent) {
            $this->transactions[$date]['categoryDiff'] = $categoryDiff;
            $this->transactions[$date]['firstSchemeValue'] = $firstSchemeValue;
            $this->transactions[$date]['secondSchemeValue'] = $secondSchemeValue;

            if ($firstSchemeValue > $secondSchemeValue) {
                $sellScheme = $data['first_scheme'];
                $buyScheme = $data['second_scheme'];
                $diff = numberFormatPrecision(abs($secondSchemeValue - $firstSchemeValue) / 2, 2);
            } else if ($secondSchemeValue > $firstSchemeValue) {
                $sellScheme = $data['second_scheme'];
                $buyScheme = $data['first_scheme'];
                $diff = numberFormatPrecision(abs($firstSchemeValue - $secondSchemeValue) / 2, 2);
            }

            $this->transactionPackage = $this->usePackage(MfTransactions::class);

            $sellTransaction = [];
            $sellTransaction['type'] = 'sell';
            $sellTransaction['amfi_code'] = $this->schemes[$sellScheme]['amfi_code'];
            $sellTransaction['scheme'] = $this->schemes[$sellScheme]['id'];
            $sellTransaction['date'] = $date;
            $sellTransaction['amount'] = (float) $diff;
            $sellTransaction['portfolio_id'] = $data['portfolio_id'];
            $sellTransaction['amc_transaction_id'] = '';
            $sellTransaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
            $sellTransaction['via_strategies'] = true;
            $this->transactions[$date]['sell']['scheme'] = $this->schemes[$sellScheme]['name'];
            $this->transactions[$date]['sell']['nav'] = $this->schemes[$sellScheme]['navs']['navs'][$date]['nav'];
            $this->transactions[$date]['sell']['units'] = $this->portfolio['investments'][$sellScheme]['units'];
            $this->transactions[$date]['sell']['transaction_amount'] = (float) $diff;


            if (!$this->transactionPackage->addMfTransaction($sellTransaction)) {
                $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $data['portfolio_id']], true);

                $this->addResponse(
                    $this->transactionPackage->packagesData->responseMessage,
                    $this->transactionPackage->packagesData->responseCode,
                    $this->transactionPackage->packagesData->responseData ?? []
                );

                return false;
            }

            $this->transactionPackage = $this->usePackage(MfTransactions::class);

            $buyTransaction = [];
            $buyTransaction['type'] = 'buy';
            $buyTransaction['amfi_code'] = $this->schemes[$buyScheme]['amfi_code'];
            $buyTransaction['scheme'] = $this->schemes[$buyScheme]['id'];
            $buyTransaction['date'] = $date;
            $buyTransaction['amount'] = (float) $diff;
            $buyTransaction['portfolio_id'] = $data['portfolio_id'];
            $buyTransaction['amc_transaction_id'] = '';
            $buyTransaction['details'] = 'Added via Strategy:' . $this->strategyDisplayName;
            $buyTransaction['via_strategies'] = true;
            $this->transactions[$date]['buy']['scheme'] = $this->schemes[$buyScheme]['name'];
            $this->transactions[$date]['buy']['nav'] = $this->schemes[$buyScheme]['navs']['navs'][$date]['nav'];
            $this->transactions[$date]['buy']['units'] = $this->portfolio['investments'][$buyScheme]['units'];
            $this->transactions[$date]['buy']['transaction_amount'] = (float) $diff;

            if (!$this->transactionPackage->addMfTransaction($buyTransaction)) {
                $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $data['portfolio_id']], true);

                $this->addResponse(
                    $this->transactionPackage->packagesData->responseMessage,
                    $this->transactionPackage->packagesData->responseCode,
                    $this->transactionPackage->packagesData->responseData ?? []
                );

                return false;
            }

            $this->portfolioPackage->recalculatePortfolio(['portfolio_id' => $data['portfolio_id']], true);
            $this->portfolio = $this->portfolioPackage->getPortfolioById($data['portfolio_id']);

            return true;
        }

        unset($this->transactions[$date]);

        return true;
    }

    protected function getStategyArgs()
    {
        return [
        ];
    }

    public function getStrategiesTransactions($data)
    {
        if (!$this->checkData($data)) {
            return false;
        }

        $currencySymbol = '$';
        if (isset($this->access->auth->account()['profile']['locale_country_id'])) {
            $country = $this->basepackages->geoCountries->getById((int) $this->access->auth->account()['profile']['locale_country_id']);

            if ($country && isset($country['currency_symbol'])) {
                $currencySymbol = $country['currency_symbol'];
            }
        }

        $this->transactionsCount = ['buy' => 0, 'sell' => 0];
        $this->totalTransactionsAmounts = ['buy' => 0, 'sell' => 0];

        foreach ($this->startEndDates as $dateIndex => $date) {
            if ($date->isWeekend()) {
                continue;
            }

            $dateString = $date->toDateString();

            if ($data['schedule'] === 'weekly') {
                if (in_array($date->dayOfWeek(), $data['weekly_days'])) {
                    $this->transactions[$dateString] = [];
                }
            } else if ($data['schedule'] === 'monthly') {
                if (in_array($date->month, $data['monthly_months']) &&
                    $date->day == $data['monthly_day']
                ) {
                    $this->transactions[$dateString] = [];
                }
            }
        }

        if (count($this->transactions) > 0) {
            $this->addResponse(
                'Calculated Transactions',
                0,
                [
                    'total_transactions_count'      => $this->transactionsCount,
                    'total_transactions_amount'     =>
                        'Buy: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['buy'], 'en_IN')) .
                        ' Sell: ' . $currencySymbol .
                        str_replace('EN_ ',
                            '',
                            (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                ->formatCurrency($this->totalTransactionsAmounts['sell'], 'en_IN'))
                    ,
                    'first_date'                    => $data['start_date'],
                    'last_date'                     => $data['end_date'],
                    'transactions'                  => []
                ]
            );

            return $this->transactions;
        }

        $this->addResponse('Error calculating transactions, check dates!', 1);
    }

    protected function checkData(&$data)
    {
        $this->portfolioPackage = $this->usePackage(MfPortfolios::class);

        $this->portfolio = $this->portfolioPackage->getPortfolioById($data['portfolio_id']);

        if (!isset($data['first_scheme']) && !isset($data['second_scheme'])) {
            $this->addResponse('Please provide 2 schemes to compare!', 1);

            return false;
        }

        if ($data['first_scheme'] == $data['second_scheme']) {
            $this->addResponse('Please provide 2 different schemes to compare!', 1);

            return false;
        }

        if (!isset($this->portfolio['investments'][$data['first_scheme']]) && !isset($this->portfolio['investments'][$data['second_scheme']])) {
            $this->addResponse('Schemes provided are not part of this portfolio!', 1);

            return false;
        }

        if (!isset($data['start_date']) && !isset($data['end_date'])) {
            $this->addResponse('Please provide start and end dates!', 1);

            return false;
        }

        $firstSchemeStartDate = \Carbon\Carbon::parse($this->portfolio['investments'][$data['first_scheme']]['start_date']);
        $secondSchemeStartDate = \Carbon\Carbon::parse($this->portfolio['investments'][$data['second_scheme']]['start_date']);
        $schemeStartDate = $firstSchemeStartDate;

        if ($secondSchemeStartDate->gt($firstSchemeStartDate)) {
            $schemeStartDate = $secondSchemeStartDate;
        }
        $firstSchemeEndDate = \Carbon\Carbon::parse($this->portfolio['investments'][$data['first_scheme']]['latest_value_date']);
        $secondSchemeEndDate = \Carbon\Carbon::parse($this->portfolio['investments'][$data['second_scheme']]['latest_value_date']);
        $schemeEndDate = $firstSchemeEndDate;

        if ($secondSchemeEndDate->lt($firstSchemeEndDate)) {
            $schemeEndDate = $secondSchemeStartDate;
        }

        $providedStartDate = \Carbon\Carbon::parse($data['start_date']);
        $providedEndDate = \Carbon\Carbon::parse($data['end_date']);

        if ($providedStartDate->lt($schemeStartDate)) {
            $data['start_date'] = $schemeStartDate->toDateString();
        }

        if ($providedEndDate->gt($schemeEndDate)) {
            $data['end_date'] = $schemeEndDate->toDateString();
        }

        try {
            $this->startEndDates = (\Carbon\CarbonPeriod::between($data['start_date'], $data['end_date']))->toArray();
        } catch (\throwable $e) {
            $this->addResponse('Dates provided are incorrect', 1);

            return false;
        }

        if (!isset($data['threshold_percent'])) {
            $this->addResponse('Please provide threshold percent!', 1);

            return false;
        }

        if (str_contains($data['threshold_percent'], '-')) {
            $this->addResponse('Please provide a positive threshold percent!', 1);

            return false;
        }

        return true;
    }
}