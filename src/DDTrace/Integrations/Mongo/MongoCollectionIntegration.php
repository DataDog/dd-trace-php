<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

final class MongoCollectionIntegration extends Integration
{
    const CLASS_NAME = 'MongoCollection';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return MongoIntegration::NAME;
    }

    protected static function loadIntegration()
    {
        if (Versions::phpVersionMatches('5.4')) {
            return;
        }

        // MongoCollection::__construct ( MongoDB $db , string $name )
        self::traceMethod('__construct', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_DATABASE, (string) $args[0]);
            $span->setTag(Tag::MONGODB_COLLECTION, $args[1]);
        });
        // int MongoCollection::count ([ array $query = array() [, array $options = array() ]] )
        self::traceMethod('count', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // array MongoCollection::createDBRef ( mixed $document_or_id )
        self::traceMethod('createDBRef', null, function (Span $span, $ref) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (!is_array($ref)) {
                return;
            }
            if (isset($ref['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, (string) $ref['$id']);
            }
            if (isset($ref['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $ref['$ref']);
            }
        });
        // array MongoCollection::distinct ( string $key [, array $query ] )
        self::traceMethod('distinct', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[1])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[1]));
            }
        });
        // MongoCursor MongoCollection::find ([ array $query = array() [, array $fields = array() ]] )
        self::traceMethod('find', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // array MongoCollection::findAndModify ( array $query [, array $update [, array $fields [, array $options ]]] )
        self::traceMethod('findAndModify', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
        });
        // array MongoCollection::findOne ([ array $query = array() [, array $fields = array()
        // [, array $options = array() ]]] )
        self::traceMethod('findOne', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // array MongoCollection::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0]['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, $args[0]['$id']);
            }
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        // bool|array MongoCollection::remove ([ array $criteria = array() [, array $options = array() ]] )
        self::traceMethod('remove', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // bool MongoCollection::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        });
        // bool|array MongoCollection::update ( array $criteria , array $new_object [, array $options = array() ] )
        self::traceMethod('update', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });

        $setIntegration = function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
        };

        // Methods that don't need extra tags added
        self::traceMethod('aggregate', $setIntegration);
        self::traceMethod('aggregateCursor', $setIntegration);
        self::traceMethod('batchInsert', $setIntegration);
        self::traceMethod('createIndex', $setIntegration);
        self::traceMethod('deleteIndex', $setIntegration);
        self::traceMethod('deleteIndexes', $setIntegration);
        self::traceMethod('drop', $setIntegration);
        self::traceMethod('getIndexInfo', $setIntegration);
        self::traceMethod('getName', $setIntegration);
        self::traceMethod('getReadPreference', $setIntegration);
        self::traceMethod('getWriteConcern', $setIntegration);
        self::traceMethod('group', $setIntegration);
        self::traceMethod('insert', $setIntegration);
        self::traceMethod('parallelCollectionScan', $setIntegration);
        self::traceMethod('save', $setIntegration);
        self::traceMethod('setWriteConcern', $setIntegration);
        self::traceMethod('validate', $setIntegration);
    }

    /**
     * @param Span $span
     * @param string $method
     */
    public static function setDefaultTags(Span $span, $method)
    {
        MongoIntegration::setDefaultTags($span, $method);
    }
}
