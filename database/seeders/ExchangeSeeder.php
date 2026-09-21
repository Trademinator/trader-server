<?php

namespace Database\Seeders;

use App\Models\Exchange;
use ccxt;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ExchangeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Exchange::count() == 0)
        {
            $colour = new \Console_Color2();
            foreach (\ccxt\Exchange::$exchanges as $exchange)
            {
                $payload = [
                    'name' => $exchange,
                    'class' => $exchange,
                    'config' => '{}',
                ];
                $newExchange = Exchange::create($payload);
                echo $colour->convert($newExchange->name.'('.$newExchange->class.') => %B'.$newExchange->exchange_id.'%n').PHP_EOL;
            }
        }
    }
}
