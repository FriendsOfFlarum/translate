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
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostTranslation>
 */
class PostTranslationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'language' => 'en',
            'content' => '<t><p>'.$this->faker->sentence().'</p></t>',
            'provider' => 'deepl',
            'update_needed' => false,
            'edited_by' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];
    }
}
