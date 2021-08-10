<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Client;

class DatabaseCustomServerTest extends Scope
{
    use DatabaseBase;
    use ProjectCustom;
    use SideServer;

    public function testDeleteCollection()
    {
        /**
         * Test for SUCCESS
         */

        // Create collection
        $actors = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Actors',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['name'], 'Actors');

        $firstName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'firstName',
            'size' => 256,
            'required' => true,
        ]);

        $lastName = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'lastName',
            'size' => 256,
            'required' => true,
        ]);

        // wait for database worker to finish creating attributes
        sleep(5);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []); 

        $this->assertEquals($collection['body']['$id'], $firstName['body']['$collection']);
        $this->assertEquals($collection['body']['$id'], $lastName['body']['$collection']);
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertCount(2, $collection['body']['attributes']);
        $this->assertEquals($collection['body']['attributes'][0]['$id'], $firstName['body']['$id']);
        $this->assertEquals($collection['body']['attributes'][1]['$id'], $lastName['body']['$id']);

        // Add Documents to the collection
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Tom',
                'lastName' => 'Holland',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $actors['body']['$id'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'firstName' => 'Samuel',
                'lastName' => 'Jackson',
            ],
            'read' => ['user:'.$this->getUser()['$id']],
            'write' => ['user:'.$this->getUser()['$id']],
        ]);

        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertEquals($document1['body']['$collection'], $actors['body']['$id']);
        $this->assertIsArray($document1['body']['$read']);
        $this->assertIsArray($document1['body']['$write']);
        $this->assertCount(1, $document1['body']['$read']);
        $this->assertCount(1, $document1['body']['$write']);
        $this->assertEquals($document1['body']['firstName'], 'Tom');
        $this->assertEquals($document1['body']['lastName'], 'Holland');

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertEquals($document2['body']['$collection'], $actors['body']['$id']);
        $this->assertIsArray($document2['body']['$read']);
        $this->assertIsArray($document2['body']['$write']);
        $this->assertCount(1, $document2['body']['$read']);
        $this->assertCount(1, $document2['body']['$write']);
        $this->assertEquals($document2['body']['firstName'], 'Samuel');
        $this->assertEquals($document2['body']['lastName'], 'Jackson');

        // Delete the actors collection
        $response = $this->client->call(Client::METHOD_DELETE, '/database/collections/'.$actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 204);
        $this->assertEquals($response['body'],"");

        // Try to get the collection and check if it has been deleted
        $response = $this->client->call(Client::METHOD_GET, '/database/collections/'.$actors['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id']
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 404);
    }

    public function testIndexLimitException()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'testLimitException',
            'read' => ['role:all'],
            'write' => ['role:all'],
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['name'], 'testLimitException');

        $collectionId = $collection['body']['$id'];

        // add unique attributes for indexing
        for ($i=0; $i < 64; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createAttribute('indexLimit', "test{$i}", Database::VAR_STRING, 16, true));
            $attribute = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'id' => "attribute{$i}",
                'type' => 'string',
                'size' => 64,
                'required' => true,
            ]);

            $this->assertEquals($attribute['headers']['status-code'], 201);
        }

        sleep(10);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertIsArray($collection['body']['attributesInQueue']);
        $this->assertIsArray($collection['body']['indexesInQueue']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(0, $collection['body']['indexes']);
        $this->assertCount(0, $collection['body']['attributesInQueue']);
        $this->assertCount(0, $collection['body']['indexesInQueue']);

        // testing for indexLimit = 64
        // MariaDB, MySQL, and MongoDB create 3 indexes per new collection
        // Add up to the limit, then check if the next index throws IndexLimitException
        for ($i=0; $i < 61; $i++) {
            // $this->assertEquals(true, static::getDatabase()->createIndex('indexLimit', "index{$i}", Database::INDEX_KEY, ["test{$i}"], [16]));
            $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/indexes', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-key' => $this->getProject()['apiKey']
            ]), [
                'id' => "key_attribute{$i}",
                'type' => 'key',
                'attributes' => ["attribute{$i}"],
            ]);

            $this->assertEquals(201, $index['headers']['status-code']);
            $this->assertEquals("key_attribute{$i}", $index['body']['$id']);
        }

        sleep(20);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals($collection['headers']['status-code'], 200);
        $this->assertEquals($collection['body']['name'], 'testLimitException');
        $this->assertIsArray($collection['body']['attributes']);
        $this->assertIsArray($collection['body']['indexes']);
        $this->assertIsArray($collection['body']['attributesInQueue']);
        $this->assertIsArray($collection['body']['indexesInQueue']);
        $this->assertCount(0, $collection['body']['attributesInQueue']);
        $this->assertCount(0, $collection['body']['indexesInQueue']);
        $this->assertCount(64, $collection['body']['attributes']);
        $this->assertCount(61, $collection['body']['indexes']);

        $tooMany = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'id' => 'tooMany',
            'type' => 'key',
            'attributes' => ['attribute62'],
        ]);

        $this->assertEquals(400, $tooMany['headers']['status-code']);
    }
}