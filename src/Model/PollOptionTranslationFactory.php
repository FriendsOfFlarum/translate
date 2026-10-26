<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Model;

use Carbon\Carbon;
use FoF\Polls\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollOptionTranslation>
 */
class PollOptionTranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'option_id' => PollOption::factory(),
            'language' => 'en',
            'answer' => $this->faker->word(),
            'provider' => 'deepl',
            'update_needed' => false,
            'edited_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
