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
use FoF\Polls\Poll;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollTranslation>
 */
class PollTranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'language' => 'en',
            'question' => $this->faker->sentence(),
            'subtitle' => null,
            'image_alt' => null,
            'provider' => 'deepl',
            'update_needed' => false,
            'edited_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
