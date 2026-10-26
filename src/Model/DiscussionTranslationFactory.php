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
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiscussionTranslation>
 */
class DiscussionTranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'discussion_id' => Discussion::factory(),
            'language' => 'en',
            'translation' => $this->faker->sentence(),
            'provider' => 'deepl',
            'update_needed' => false,
            'edited_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
