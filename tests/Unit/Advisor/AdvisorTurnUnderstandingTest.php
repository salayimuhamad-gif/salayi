<?php

declare(strict_types=1);

namespace Tests\Unit\Advisor;

use App\Modules\Advisor\Services\AdvisorLanguage;
use App\Modules\Advisor\Services\AdvisorTurnUnderstanding;
use App\Modules\Advisor\Services\AiGateway;
use Tests\TestCase;

/**
 * The validation boundary where model output becomes application state.
 *
 * Everything here goes through {@see AdvisorTurnUnderstanding::validate()}
 * with raw strings — the exact position malformed or hostile provider output
 * arrives in. The invariant: nothing crosses that a hand-written form
 * validator would not accept, and failure is always null, never an exception
 * and never a partial write.
 */
final class AdvisorTurnUnderstandingTest extends TestCase
{
    private function understanding(): AdvisorTurnUnderstanding
    {
        return new AdvisorTurnUnderstanding(new AiGateway([]), new AdvisorLanguage);
    }

    public function test_malformed_json_yields_null_not_an_exception(): void
    {
        $this->assertNull($this->understanding()->validate('not json'));
        $this->assertNull($this->understanding()->validate('{"intent": "provide_info", '));
        $this->assertNull($this->understanding()->validate('"just a string"'));
        $this->assertNull($this->understanding()->validate(''));
    }

    public function test_a_fenced_json_block_is_accepted(): void
    {
        $result = $this->understanding()->validate("```json\n{\"intent\":\"compare\",\"criteria_updates\":{},\"referenced_positions\":[1,2],\"needs_clarification\":false}\n```");

        $this->assertSame('compare', $result['intent']);
        $this->assertSame([1, 2], $result['referenced_positions']);
    }

    public function test_unknown_intents_and_keys_are_dropped_not_trusted(): void
    {
        $result = $this->understanding()->validate(json_encode([
            'intent' => 'execute_shell_command',
            'criteria_updates' => [
                'purpose' => 'residence',
                'budget' => '999999999',
                'password' => 'stolen',
                'is_admin' => true,
            ],
            'referenced_positions' => [1],
            'needs_clarification' => 'yes',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('other', $result['intent']);
        // Only the allowlisted key survives; the budget NEVER arrives through
        // the model, and invented keys vanish.
        $this->assertSame(['purpose' => 'residence'], $result['criteria_updates']);
        // A non-boolean clarification flag is false, not truthy.
        $this->assertFalse($result['needs_clarification']);
    }

    public function test_enum_slots_reject_values_outside_the_enum(): void
    {
        $result = $this->understanding()->validate(json_encode([
            'intent' => 'provide_info',
            'criteria_updates' => [
                'purpose' => 'speculation',
                'property_type' => 'castle',
                'currency' => 'EUR',
                'area' => 'Ankawa',
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(['area' => 'Ankawa'], $result['criteria_updates']);
    }

    public function test_free_text_values_are_bounded_and_control_stripped(): void
    {
        $hostile = str_repeat('ا', 500)."\x00\x1B[2J";

        $result = $this->understanding()->validate(json_encode([
            'intent' => 'provide_info',
            'criteria_updates' => ['area' => $hostile],
        ], JSON_THROW_ON_ERROR));

        $area = $result['criteria_updates']['area'];
        $this->assertLessThanOrEqual(200, mb_strlen($area));
        $this->assertStringNotContainsString("\x00", $area);
        $this->assertStringNotContainsString("\x1B", $area);
    }

    public function test_referenced_positions_are_bounded_small_integers(): void
    {
        $result = $this->understanding()->validate(json_encode([
            'intent' => 'compare',
            'referenced_positions' => [1, '2', 99, -4, 'DROP TABLE', 3.7, 2],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([1, 2], $result['referenced_positions']);
    }

    public function test_an_unconfigured_gateway_understands_nothing_and_says_so(): void
    {
        // The whole understand() path with no provider: null, immediately,
        // with no HTTP attempted (an empty gateway has nothing to call).
        $this->assertNull($this->understanding()->understand('سڵاو', [], 'ckb'));
    }
}
