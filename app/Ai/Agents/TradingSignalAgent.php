<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('anthropic')]
#[Model('claude-haiku-4-5-20251001')]
class TradingSignalAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are a trading signal engine. Analyze the provided price and sentiment data for a stock symbol and return a structured trading signal. Be concise and data-driven. Never explain your reasoning beyond the reason field.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum('buy', 'sell', 'hold')->required(),
            'confidence' => $schema->number()->minimum(0)->maximum(1)->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
