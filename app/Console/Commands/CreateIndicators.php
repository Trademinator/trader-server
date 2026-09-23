<?php

namespace App\Console\Commands;

use App\Repositories\ExchangeRepository;
use App\Repositories\TickerRepository;
use App\Traits\AnalizeTicker;
use App\Traits\IsSupportedByCCXT;
use App\Traits\Technical;
use Illuminate\Console\Command;
use function Trademinator\Time\to_unixtime;

class CreateIndicators extends Command
{
    use AnalizeTicker, IsSupportedByCCXT, Technical;
    protected ExchangeRepository $exchangeRepository;
    protected TickerRepository $tickerRepository;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trademinator:CreateIndicators {exchange} {symbol} {period} {from?} {to?} {--debug}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculates the indicators needed by Trademinator';

    public function __construct(ExchangeRepository $exchangeRepository, TickerRepository $tickerRepository)
    {
        parent::__construct();
        $this->exchangeRepository = $exchangeRepository;
        $this->tickerRepository = $tickerRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $className = $this->argument('exchange');
        $symbol = $this->argument('symbol');
        $period = $this->argument('period');
        if ($this->isSupportedByCCXT($className, $symbol, $period))
        {
            $debugExchange = $this->option('debug'); $extraSettings = [];
            $extraSettings['verbose'] = $debugExchange;
            $exchanges = $this->exchangeRepository->findByClass($className);
            $startTime =  to_unixtime($this->argument('from') ?? 'yesterday');
            // if {to} is not specified, then is today
            $endtTime = to_unixtime($this->argument('to') ?? 'now');

            foreach ($exchanges as $exchange)
            {
                $this->exchangeRepository->setExchange($exchange, $extraSettings);
                //$tickers = $this->exchangeRepository->fetch($symbol, $period, $startTime, $endtTime);

                $tickers = $this->tickerRepository->fetchFromDB($className, $symbol, $period, $startTime * 1000, $endtTime * 1000);

                $colour = new \Console_Color2();
                //foreach ($tickers as $ticker)
                //{
                //}
                $this->ema($tickers, 3, 'close');
                print_r($tickers);
                $this->tickerRepository->updateTickers($className, $symbol, $period, $tickers);
                $this->info('The command was successful!');
            }
        }
        else
        {
            $this->error($className . '-' . $symbol . '-' . $period . ' tuple is not supported.');
            return 1;
        }
    }
}
