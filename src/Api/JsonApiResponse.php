<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */
namespace FoF\Translate\Api;

use Flarum\Api\Context;
use Flarum\Api\Serializer;
use Psr\Http\Message\ResponseInterface;

use function Tobyz\JsonApiServer\json_api_response;

/**
 * Serializes a model of another resource type from a custom endpoint: the translate
 * endpoints live on posts/discussions/polls (for their visibility scope) but return
 * translation resources. Pattern from fof/ban-ips.
 */
abstract class JsonApiResponse
{
    /**
     * @param array<string, array<string, mixed>> $include include tree, e.g. ['options' => []]
     */
    public static function one(Context $context, string $type, object $model, array $include = []): ResponseInterface
    {
        $context = $context->withCollection($context->api->getCollection($type));

        $serializer = new Serializer($context);
        $serializer->addPrimary($context->resource($type), $model, $include);

        [$data, $included] = $serializer->serialize();

        $document = ['data' => $data[0]];

        if ($included) {
            $document['included'] = $included;
        }

        return json_api_response($document);
    }

    /**
     * A `data` list of models of several types, each serialized against its own collection.
     *
     * @param array<string, iterable<object>> $modelsByType type => models
     */
    public static function many(Context $context, array $modelsByType): ResponseInterface
    {
        $data = [];

        foreach ($modelsByType as $type => $models) {
            $typed = $context->withCollection($context->api->getCollection($type));
            $serializer = new Serializer($typed);

            foreach ($models as $model) {
                $serializer->addPrimary($typed->resource($type), $model, []);
            }

            [$primary] = $serializer->serialize();
            array_push($data, ...$primary);
        }

        return json_api_response(['data' => $data]);
    }
}
