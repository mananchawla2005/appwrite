<?php

use Appwrite\Database\Database;
use Appwrite\Database\Validator\UID;
use Appwrite\Storage\Storage;
use Appwrite\Storage\Validator\File;
use Appwrite\Storage\Validator\FileSize;
use Appwrite\Storage\Validator\FileType;
use Appwrite\Storage\Validator\Upload;
use Appwrite\Task\Validator\Cron;
use Utopia\App;
use Utopia\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Config\Config;
use Cron\CronExpression;

include_once __DIR__ . '/../shared/api.php';

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->param('name', '', function () { return new Text(128); }, 'Function name.')
    ->param('env', '', function () { return new WhiteList(array_keys(Config::getParam('environments'))); }, 'Execution enviornment.')
    ->param('vars', [], function () { return new Assoc();}, 'Key-value JSON object.', true)
    ->param('events', [], function () { return new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)); }, 'Events list.', true)
    ->param('schedule', '', function () { return new Cron(); }, 'Schedule CRON syntax.', true)
    ->param('timeout', 15, function () { return new Range(1, 900); }, 'Function maximum execution time in seconds.', true)
    ->action(function ($name, $env, $vars, $events, $schedule, $timeout, $response, $projectDB) {
        $function = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_FUNCTIONS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'dateUpdated' => time(),
            'status' => 'paused',
            'name' => $name,
            'env' => $env,
            'tag' => '',
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'previous' => null,
            'next' => null,
            'timeout' => $timeout,
        ]);

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($function->getArrayCopy())
        ;
    }, ['response', 'projectDB']);

App::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'dateCreated',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
            ],
        ]);

        $response->json(['sum' => $projectDB->getSum(), 'functions' => $results]);
    }, ['response', 'projectDB']);

App::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->action(function ($functionId, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $response->json($function->getArrayCopy());
    }, ['response', 'projectDB']);

App::get('/v1/functions/:functionId/usage')
    ->desc('Get Function Usage')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_CONSOLE])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('range', '30d', function () { return new WhiteList(['24h', '7d', '30d', '90d']); }, 'Date range.', true)
    ->action(function ($functionId, $range, $response, $project, $projectDB, $register) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $consoleDB */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Registry\Registry $register */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $period = [
            '24h' => [
                'start' => DateTime::createFromFormat('U', \strtotime('-24 hours')),
                'end' => DateTime::createFromFormat('U', \strtotime('+1 hour')),
                'group' => '30m',
            ],
            '7d' => [
                'start' => DateTime::createFromFormat('U', \strtotime('-7 days')),
                'end' => DateTime::createFromFormat('U', \strtotime('now')),
                'group' => '1d',
            ],
            '30d' => [
                'start' => DateTime::createFromFormat('U', \strtotime('-30 days')),
                'end' => DateTime::createFromFormat('U', \strtotime('now')),
                'group' => '1d',
            ],
            '90d' => [
                'start' => DateTime::createFromFormat('U', \strtotime('-90 days')),
                'end' => DateTime::createFromFormat('U', \strtotime('now')),
                'group' => '1d',
            ],
        ];

        $client = $register->get('influxdb');

        $executions = [];
        $failures = [];
        $compute = [];

        if ($client) {
            $start = $period[$range]['start']->format(DateTime::RFC3339);
            $end = $period[$range]['end']->format(DateTime::RFC3339);
            $database = $client->selectDB('telegraf');

            // Executions
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $executions[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] : 0,
                    'date' => \strtotime($point['time']),
                ];
            }

            // Failures
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' AND "functionStatus"=\'failed\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $failures[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] : 0,
                    'date' => \strtotime($point['time']),
                ];
            }

            // Compute
            $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_time" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getId().'\' AND "functionId"=\''.$function->getId().'\' GROUP BY time('.$period[$range]['group'].') FILL(null)');
            $points = $result->getPoints();

            foreach ($points as $point) {
                $compute[] = [
                    'value' => (!empty($point['value'])) ? $point['value'] / 60000 : 0, // minutes
                    'date' => \strtotime($point['time']),
                ];
            }
        }

        $response->json([
            'range' => $range,
            'executions' => [
                'data' => $executions,
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $executions)),
            ],
            'failures' => [
                'data' => $failures,
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $failures)),
            ],
            'compute' => [
                'data' => $compute,
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $compute)),
            ],
        ]);
    }, ['response', 'project', 'projectDB', 'register']);

App::put('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Update Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('name', '', function () { return new Text(128); }, 'Function name.')
    ->param('vars', [], function () { return new Assoc();}, 'Key-value JSON object.', true)
    ->param('events', [], function () { return new ArrayList(new WhiteList(array_keys(Config::getParam('events')), true)); }, 'Events list.', true)
    ->param('schedule', '', function () { return new Cron(); }, 'Schedule CRON syntax.', true)
    ->param('timeout', 15, function () { return new Range(1, 900); }, 'Function maximum execution time in seconds.', true)
    ->action(function ($functionId, $name, $vars, $events, $schedule, $timeout, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $cron = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? CronExpression::factory($schedule) : null;
        $next = (!empty($function->getAttribute('tag', null)) && !empty($schedule)) ? $cron->getNextRunDate()->format('U') : null;

        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            'dateUpdated' => time(),
            'name' => $name,
            'vars' => $vars,
            'events' => $events,
            'schedule' => $schedule,
            'previous' => null,
            'next' => $next,
            'timeout' => $timeout,   
        ]));

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response->json($function->getArrayCopy());
    }, ['response', 'projectDB']);

App::patch('/v1/functions/:functionId/tag')
    ->groups(['api', 'functions'])
    ->desc('Update Function Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateTag')
    ->label('sdk.description', '/docs/references/functions/update-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tag', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(function ($functionId, $tag, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);
        $tag = $projectDB->getDocument($tag);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $schedule = $function->getAttribute('schedule', '');
        $cron = (!empty($function->getAttribute('tag')&& !empty($schedule))) ? CronExpression::factory($schedule) : null;
        $next = (!empty($function->getAttribute('tag')&& !empty($schedule))) ? $cron->getNextRunDate()->format('U') : null;

        $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
            'tag' => $tag->getId(),
            'next' => $next,
        ]));

        if (false === $function) {
            throw new Exception('Failed saving function to DB', 500);
        }

        $response->json($function->getArrayCopy());
    }, ['response', 'projectDB']);

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->action(function ($functionId, $response, $project, $projectDB, $deletes) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $deletes */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        if (!$projectDB->deleteDocument($function->getId())) {
            throw new Exception('Failed to remove function from DB', 500);
        }

        $deletes
            ->setParam('projectId', $project->getId())
            ->setParam('document', $function->getArrayCopy())
        ;

        $response->noContent();
    }, ['response', 'project', 'projectDB', 'deletes']);

App::post('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('Create Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createTag')
    ->label('sdk.description', '/docs/references/functions/create-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('command', '', function () { return new Text('1028'); }, 'Code execution command.')
    ->param('code', [], function () { return new File(); }, 'Gzip file containing your code.', false)
    // ->param('code', '', function () { return new Text(128); }, 'Code package. Use the '.APP_NAME.' code packager to create a deployable package file.')
    ->action(function ($functionId, $command, $code, $request, $response, $projectDB, $usage) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $file = $request->getFiles('code');
        $device = Storage::getDevice('functions');
        $fileType = new FileType([FileType::FILE_TYPE_GZIP]);
        $fileSize = new FileSize(App::getEnv('_APP_STORAGE_LIMIT', 0));
        $upload = new Upload();

        if (empty($file)) {
            throw new Exception('No file sent', 400);
        }

        // Make sure we handle a single file and multiple files the same way
        $file['name'] = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $file['tmp_name'] = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $file['size'] = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        // Check if file type is allowed (feature for project settings?)
        // if (!$fileType->isValid($file['tmp_name'])) {
        //     throw new Exception('File type not allowed', 400);
        // }

        if (!$fileSize->isValid($file['size'])) { // Check if file size is exceeding allowed limit
            throw new Exception('File size not allowed', 400);
        }

        if (!$upload->isValid($file['tmp_name'])) {
            throw new Exception('Invalid file', 403);
        }

        // Save to storage
        $size = $device->getFileSize($file['tmp_name']);
        $path = $device->getPath(\uniqid().'.'.\pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!$device->upload($file['tmp_name'], $path)) { // TODO deprecate 'upload' and replace with 'move'
            throw new Exception('Failed moving file', 500);
        }
        
        $tag = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_TAGS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'command' => $command,
            'codePath' => $path,
            'codeSize' => $size,
        ]);

        if (false === $tag) {
            throw new Exception('Failed saving tag to DB', 500);
        }

        $usage
            ->setParam('storage', $tag->getAttribute('codeSize', 0))
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($tag->getArrayCopy())
        ;
    }, ['request', 'response', 'projectDB', 'usage']);

App::get('/v1/functions/:functionId/tags')
    ->groups(['api', 'functions'])
    ->desc('List Tags')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listTags')
    ->label('sdk.description', '/docs/references/functions/list-tags.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(function ($functionId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'dateCreated',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_TAGS,
                'functionId='.$function->getId(),
            ],
        ]);

        $response->json(['sum' => $projectDB->getSum(), 'tags' => $results]);
    }, ['response', 'projectDB']);

App::get('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Get Tag')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getTag')
    ->label('sdk.description', '/docs/references/functions/get-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tagId', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(function ($functionId, $tagId, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $projectDB->getDocument($tagId);

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $response->json($tag->getArrayCopy());
    }, ['response', 'projectDB']);

App::delete('/v1/functions/:functionId/tags/:tagId')
    ->groups(['api', 'functions'])
    ->desc('Delete Tag')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteTag')
    ->label('sdk.description', '/docs/references/functions/delete-tag.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('tagId', '', function () { return new UID(); }, 'Tag unique ID.')
    ->action(function ($functionId, $tagId, $response, $projectDB, $usage) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $tag = $projectDB->getDocument($tagId);

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found', 404);
        }

        $device = Storage::getDevice('functions');

        if ($device->delete($tag->getAttribute('codePath', ''))) {
            if (!$projectDB->deleteDocument($tag->getId())) {
                throw new Exception('Failed to remove tag from DB', 500);
            }
        }

        if($function->getAttribute('tag') === $tag->getId()) { // Reset function tag
            $function = $projectDB->updateDocument(array_merge($function->getArrayCopy(), [
                'tag' => '',
            ]));
    
            if (false === $function) {
                throw new Exception('Failed saving function to DB', 500);
            }
        }

        $usage
            ->setParam('storage', $tag->getAttribute('codeSize', 0) * -1)
        ;

        $response->noContent();
    }, ['response', 'projectDB', 'usage']);

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'functions.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    // ->param('async', 1, function () { return new Range(0, 1); }, 'Execute code asynchronously. Pass 1 for true, 0 for false. Default value is 1.', true)
    ->action(function ($functionId, /*$async,*/ $response, $project, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */

        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $tag = $projectDB->getDocument($function->getAttribute('tag'));

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }

        if (empty($tag->getId()) || Database::SYSTEM_COLLECTION_TAGS != $tag->getCollection()) {
            throw new Exception('Tag not found. Deploy tag before trying to execute a function', 404);
        }
        
        $execution = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'status' => 'waiting', // waiting / processing / completed / failed
            'trigger' => 'http', // http / schedule / event
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0,
        ]);

        if (false === $execution) {
            throw new Exception('Failed saving execution to DB', 500);
        }
        
        // Issue a TLS certificate when domain is verified
        Resque::enqueue('v1-functions', 'FunctionsV1', [
            'projectId' => $project->getId(),
            'functionId' => $function->getId(),
            'executionId' => $execution->getId(),
            'functionTag' => $tag->getId(),
            'functionTrigger' => 'http',
        ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($execution->getArrayCopy())
        ;
    }, ['response', 'project', 'projectDB']);

App::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List Executions')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(function ($functionId, $search, $limit, $offset, $orderType, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }
        
        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'dateCreated',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_EXECUTIONS,
                'functionId='.$function->getId(),
            ],
        ]);

        $response->json(['sum' => $projectDB->getSum(), 'executions' => $results]);
    }, ['response', 'projectDB']);

App::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get Execution')
    ->label('scope', 'functions.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->param('functionId', '', function () { return new UID(); }, 'Function unique ID.')
    ->param('executionId', '', function () { return new UID(); }, 'Execution unique ID.')
    ->action(function ($functionId, $executionId, $response, $projectDB) {
        $function = $projectDB->getDocument($functionId);

        if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
            throw new Exception('Function not found', 404);
        }

        $execution = $projectDB->getDocument($executionId);

        if($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Execution not found', 404);
        }

        if (empty($execution->getId()) || Database::SYSTEM_COLLECTION_EXECUTIONS != $execution->getCollection()) {
            throw new Exception('Execution not found', 404);
        }

        $response->json($execution->getArrayCopy());
    }, ['response', 'projectDB']);