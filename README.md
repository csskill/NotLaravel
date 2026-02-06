# FRAMEWORK README

NOTE: This readme is currently not up-to-date and will be rewritten in the near future

Full detailed overview and explanation of the framework.
It is custom built with heavy inspiration from the Laravel framework and follow a lot of the same conventions as Laravel.

## Project overview

1. Folder structure
    1. [Bootstrap](#bootstrap)
    2. [App](#app)
    3. [Public](#public)
    4. [Resources](#resources)
    5. [Scripts](#scripts)
    6. [Storage](#storage)
    7. [Other folders](#other-local-folders-and-files)
2. [Compilers](#external-compilers)
3. [Frontend Framework](#frontend-framework)
4. [Autoloaders](#autoloaders)
5. [CLI](#the-nraa-cli-helper)
6. [Setup ViewModel](#short-introduction-to-setting-up-a-new-viewmodel)
    1. [Create a model](#1-create-a-model)
    2. [Create a controller](#2-create-a-controller)
    3. [Create a route](#3-create-a-route-to-go-with-the-controller)
7. [Routing](#routing)
    1. [Add controller](#add-a-controller)
    2. [Anonymous function](#add-a-anonymous-function)
    3. [Router functions](#the-router-functions)
    4. [Parameter binding](#parameter-binding)
    5. [Authentication middleware](#authentication-and-middleware-on-routes)
    6. [Dependency injection](#magic-dependency-injection)
8. [Models](#models)
9. [Workmanager](#jobs)
10. [Application configuration](#application-config)
11. [Logging](#logging)
12. [Events](#events-and-eventlisteners)
13. [Jobs](#jobs)
    1. [Register Job](#register-a-job-to-be-run-right-away)
    2. [Schedule a job](#register-a-job-to-be-run-at-a-specific-timepoint)
    3. [Recurring jobs](#register-a-recurring-job)
14. [Filesystem](#filesystem)    
15. [Configuration and deployment](#configuration-and-deployment)

## Overview of folder structure and framework

## Bootstrap

The httpdocs/bootstrap/ folder is where the framework lives

## App

The httpdocs/app/ folder is where the application is built on top of the framework. 
All controllers, models, services, jobs etc. will be written into this folder 

### config

httpdocs/app/config

Contains all configuration files for the application. 

### Controllers

httpdocs/app/Controllers

Contains all PHP Controllers created for the application

### Events

httpdocs/app/Events 

contains all Events for the application 

### Helpers

httpdocs/app/Helpers

A folder where the user can place helper functions for their application

### Http

httpdocs/app/Http

Currently misplaced folder. Should be part of the bootstrap application

### Jobs

httpdocs/app/Jobs

This is where you should place all Jobs that will be queued in the jobsystem

### Listeners

httpdocs/app/Listeners

This is where you place all listeners configured to listen for Events 

### Middleware

httpdocs/app/Middleware

This is where you should place all middleware files you plan to use with router endpoints

### Models

httpdocs/app/Models

Where all models to be used with the database should be placed

### Services

httpdocs/app/Services

Where all services should be placed

## Public

The httpdocs/public/ folder is where the web server is configured to serve results from.
This is to keep the application structure and web platform separated and secure.

This folder should only contain static content such as images etc that should be available directly from the frontend.
CSS and Javascript is compiled by the yarn compiler and placed in their respective folder within public

## Resources 

The resources folder contains all files needed to build and serve the frontend. 

resources/css:

Contains scss files which are compiled by the yarn compiler

resources/js:

Contains all javascript or typescript files that will be compiled by the yarn compiler

resources/views:

Contains all Twig template files used by the Twig frontend framework to serve frontend content

## Scripts

Scripts contain any bash script that should be run when building or spinning up the application.
It can also contain needed scripts to manually run updates on the database etc.

## Storage

The storage folder is used for all types of local storage. This will contain cache file for the frontend template system Twig,
logfiles if the logging system is set up to write to filesystem and any other configured localstorage based on the Filesystem configuration. 

## External compilers 

External dependencies for the project is set up with yarn and composer.

To build production ready code for SCSS / Javascript / Typescript:

```bash
yarn production
```

To intall composer dependencies:

```bash
compoer update
```

```bash
composer install
```

### SCSS and TypeScript Build System

The project uses Webpack to compile SCSS and TypeScript files:

**Development workflow:**
```bash
# Install dependencies (first time)
yarn install

# Start development mode with auto-watch (recommended for development)
yarn dev

# Build for production (minified and optimized)
yarn build
```

**Directory structure:**
- **Source files (edit these):**
  - `resources/css/scss/` - SCSS files (compiled to public/css/styles.css)
  - `resources/js/` - TypeScript files (compiled to public/js/)
  
- **Output files (auto-generated, don't edit):**
  - `public/css/styles.css` - Compiled CSS
  - `public/js/` - Compiled JavaScript

**Important notes:**
- ❌ DO NOT edit `public/css/styles.css` directly - it will be overwritten
- ✅ DO edit SCSS files in `resources/css/scss/`

## Frontend framework

### Templating - Twig

The framework relies on the popular frontend framework Twig to render templates.

Documentation for Twig is available at:
https://twig.symfony.com/

Templates are located in `resources/views/` and are served through controllers.

### CSS Framework - Bootstrap 5.3

The project uses **Bootstrap 5.3** as the foundation for all styling and layout.

- Bootstrap CSS is included via `public/css/bootstrap.min.css`
- Custom styles are written in SCSS and compiled to `public/css/styles.css`
- Use Bootstrap classes first, then add custom styles in SCSS partials
- Bootstrap documentation: https://getbootstrap.com/docs/5.3/

**Working with styles:**
1. Use Bootstrap utility classes in Twig templates (e.g., `class="container", "btn btn-primary"`)
2. For custom styling, edit SCSS files in `resources/css/scss/`
3. Run `yarn dev` to watch for changes and auto-compile
4. Custom styles can override or extend Bootstrap defaults

**Example Twig template:**
```twig
<div class="container">
    <div class="row">
        <div class="col-md-6">
            <button class="btn btn-primary">Bootstrap Button</button>
        </div>
    </div>
</div>
```

## autoloaders

This application automagically load all php files written inside the folders bootstrap/ and app/

Naming convention:

PHP file should be named based on the class. When you create a model with class named User the file should be named User.php

## The CLI Command

In the httpdocs there is a php script named "nraa" 

This is used by the Worker system to spin up worker pools.
This can also be used to ensure indexes that are set on models are applied to the database

usage:

`php nraa list`

This will list available commands 

## Short introduction to setting up a new viewmodel 

A quick example on how to get started when creating a new Database model and belonging views. 
Taking the CRUD api as example. 

### 1. Create a Model 

A model is the object that will be stored and fetched from the database. 

Create a new file in /app/Models. Lets name it UserModel.php

Create a class named UserModel and make sure it extends MongoDBObject which will give you a 
set of functions it needs to implement, as well as inherit a lot of easy function to deal with 
insertion and updating in the database

NB: make sure you set a correct collectionName in the getCollectionName() function 

No need to do anything to the database, as the DBLayer will automatically create a collection for it 

### 2. Create a Controller 

A controller is a class that should be the layer between the Models / Database and the frontend. 
All logical handling of requests should happen here. 

Create a controller named UserController.php to go along with the UserModel that we created. 

What the functions are named in the controller does not matter, as they will have to be connected to the router via the router configuration. 

### 3. Create a route to go with the Controller

Now that both Controller and Model have been created, you need to configure a route to handle
any requests from the webpage. Read Routing below. 

## Routing

The applications Router reads the configuration from the application available in httpdocs/routes.php

Below you will find explanations on how you can configure routes based on different approaches, and how to apply middleware to each endpoint.

### Add a Controller 

Specify a Controller and a method in the format of an array as shown below. 
This will create an instance of the class HomeController and the method index

```
    Router::get('/', [HomeController::class, 'index']);
```

### Add a anonymous function

A route can also accept a function that will be triggered when the route is called

```
    Router::('/get/anon', function(){
        echo "This is a GET call to /get/anon";
    });
```

### The router functions
- Router::get
- Router::put
- Router::patch
- Router::post
- Router::delete

```
    Router::get('/users/{userId}', [UserController::class, 'index']);
```

this results in the method index on UserController to be called

If you want to update that same user, you will create a form or send a ajax call to the backend at the same route.
However this request will be sent over a PATCH or PUT request method

```
    Router::put('/users/{userId}', [UserController::class, 'update']);
```

This results in the method update on UserController to be called 

### Parameter binding 

Routes can hold dynamic properties that you want access to in the called function. This can be anything from a User ID to whatever your needs may be. Example:

```
    Router::put('/users/{userId}/images/{image}', [UserController::class, 'getImage']);
```

Here we will call up UserController::getImage. The Router will have matched up two dynamic variables from the route. 
Visiting /users/256/images/someImage would in this case result in:

userId: 256
image: "someImage"

the named variables in the route will be passed along to the specified router controllers method:

```
class UserController extends Controller {

    public function getImage($userId, $image){
        //... now you can use $userId and $image to find the correct entity in the database
    }

}
```

### Authentication and middleware on routes

Some parts of your application may need extra layers of verification before you can access them. 
These routes can be configured with Middleware to perform checks

This implementation is half-arsed so far, but it works.. 

RouteOptions allow you to define some extra parameters to your route. 

($authRequired = false, $rolesAllowed = [], $permissionsRequired = [], $middlewares = [])

```
Router::get('/admin', [AdminController::class, 'index'])
    ->setRouteOptions(
        new RouteOptions(true, ['admin'], [], ['\Nraa\Middleware\AuthenticationMiddleware'])
    );
```

### Magic dependency injection 

There might be times where you need access to services in within the applications inside your controller. 
Thanks to magic service injection you can define any registered Service providers or singletons that have been registered in 
the application startup. 

Continuing on our previous example, we have the route with two defined variables that will be passed to the controllers function.
You can add any dependency injectors you like, as long as they come before the route variables:

```
use \Nraa\Pillars\Application;
use \Nraa\Router\Router;

class UserController extends Controller {

    public function getImage(Application $app, Router $router, $userId, $image){
        // now you can use any initiated parameters of the injected services
        $basePath = $router->getBaseDir();
    }

}
```

## Models

Models are the transactional layer between the database and the frontend / controlelrs. Every model represents a collection in the database. 

Models can be created via the cli:

```
php nra new:model ModelName
```

In the Model class you defined public properties to the class. These will be mapped to fields in the database and automagically mapped when fetching documents from MongoDB.

The model class also has posibility to define relational structures to other models. 

### Setting custom indexes on a model

```
use Nraa\Database\Attributes\Index;

#[Index(keys: ['email' => 1], options: ['unique' => true])]
#[Index(keys: ['createdAt' => -1])]
class User extends Model
{
    public string $email;
    public \DateTimeImmutable $createdAt;
}
```

### HasOne 

Define a property in your model class defining the type of the Model that the one you are writing is supposed to have one of:

```
#[HasOne(className: TestModel::class, foreignKey: 'test_id', primaryKey: '_id')]
public ?TestModel $testModel = null;
```

The attribute HasOne is used to tell the transactional layer between php and MongoDb that there exists a key-pair to bind a relation between these two datasets. 

In this example, its expected that testModel has a property named 'test_id' that cointains the Id of the model you are implementing. 

To dynamically fetch documents that are related you can implement a function in your Model like this:

```
public function test()
{
    return $this->HasOne(TestModel::class);
}
```

Now you can access the related document directly in the code:

```
$yourModel = \Nraa\Models\YourModel::findOne(['id' => 'someId']);

$relatedDocument = $yourModel->test()->first();
```

### HasMany

HasMany adopts the same philosophy as HasOne and can be implemented as such:

```
#[HasMany(className: TestModel::class, foreignKey: 'test_id', primaryKey: '_id_')]
public ?TestModel $testModel = null;
```

```
public function tests()
{
    return $this->HasMany(TestModel::class);
}
```

```
$yourModel = \Nraa\Models\YourModel::findOne(['id' => 'someId']);

$relatedDocument = $yourModel->test()->toArray();
```

### BelongsTo

Belongsto is basically the opposite side of the table as HasOne and HasMany. This is where we define the opposite side of the relation. 

```
#[BelongsTo(className: YourModel::class, foreignKey: '_id', primaryKey: 'test_id')]
public ?TestModel $testModel = null;
```

```
public function model()
{
    return $this->BelongsTo(TestModel::class);
}
```

```
$test = \Nraa\Models\Tests::first();

$owner = $yourModel->model();
```

### BelongsToMany

Just look at this one like a middle aged single mom...

```
#[BelongsTo(className: YourModel::class, foreignKey: '_id', primaryKey: 'test_id')]
public ?TestModel $testModel = null;
```

```
public function models()
{
    return $this->BelongsToMany(TestModel::class);
}
```

```
$test = \Nraa\Models\Tests::first();

$owner = $yourModel->model();
```

## Application config

Configuration is currently set up with DotEnv which reads the information in the .env file found in the application root folder. 
All configuration parameters are available either via $_SERVER or $_ENV

## Logging

Implemented based on Loglevels from (RFC 5424 specification)[https://datatracker.ietf.org/doc/html/rfc5424]
which are: emergency, alert, critical, error, warning, notice, info, debug

in app/config/log.php the different log providers and channels are set up for the application. 
you can configure multiple channels for each provider. 

Driver needs to be set for each channel. the possible configs are either 'mongodb' or 'file'.

If file is chosen, you have to add a path it should write to. This means we can have seperate files for different kind of log levels. 

Example of writing to log when you want to implement logs in the solution:

```
use Nraa\Pillars\Logging\Log;
Log::warning("This is a warning logged to the configured channels")
```

You can also overturn any configuration for a channel by writing specifically to it:

```
use Nraa\Pillars\Logging\Log;
Log::channel('mongodb')->warning("This is a warning logged to the configured channels")
```

## Events and EventListeners

Lets say you register a new user in the UserController, and you now want to do a whole lot more on the side just beside adding the user to the database. 
Instead of having your controller drowning in code not relevant for the task in hand, you can notify anyone listening about the user created, and hand off the other assignments, such as sending emails, notifications etc.

First of all you have to create an event. They are stored in the /App/Events folder. All it takes is creating a public property and a contructor that assigns it. 

Events can be created via CLI:

```
php nraa new:event UserCreated
```

This will output:

```
<?php

namespace Nraa\Events;

use \Nraa\Pillars\Events\Dispatchable;

class UserCreated
{
    use Dispatchable;

    function __construct()
    {
    }
}

```

Modify the constructor to pass along any given entity or data that the listeners would like. Ex:

```
use \Nraa\Models\Users\User;

class UserCreated
{
    use Dispatchable;

    public $user

    function __construct(User $user)
    {
    }
}

```


The code will automagically find any eventlisteners that is listening to your specific event by checking the property types in the declared Handle function. 
To continue on the UserCreated event above, a listener would look like this:

```
namespace Nraa\EventListeners;

use Nraa\Workers\JobRegistrar;
use Nraa\Events\UserCreated;
use Nraa\Jobs\SendWelcomeEmailJob;

class UserCreatedEvent
{

    public function handle(UserCreated $event)
    {
        $registrar = new JobRegistrar();
        $registrar->registerJob([SendWelcomeEmailJob::class, 'send'], ['user' => $user], null, 'UserService');
    }
}
```

Eventlistners are stored in /App/EventListeners and can also be created via CLI:

```
php nraa new:eventlistener SendWelcomeEmail 
```

## Jobs

Using Supervisor to make sure that jobs are running (make a config file for each required):

```
/etc/supervisor/conf.d/vimzzz-worker.conf
```

Supervisor makes sure a script is running, and a base configuration looks like:

```
[program:vimzzz-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/vhosts/vimzzz/httpdocs/nraa app:job-worker 2
autostart=true
autorestart=true
user=root
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/vhosts/vimzzz/logs/supervisor.logx
```

The Jobs / Workers is an easy way to register a job that you want to happen in the background of the web application. This can be async task that you need to be done, that doesn't need to hold up the response to the frontend. Lets say a person is registering as a new user to your solution, and you want to send out a couple of welcoming emails. This can happen on another thread while you return the successful signup response to the user.

The worker is made using the Symfony\CLI and React\EventLoop library to perform tasks async, separated from the web application. A worker needs to be started 
using the comman ```php nraa app:job-worker``` from the root directory of the application. 

Jobs can either be set to be run straight away as they are registered, or be set up to run at a specific time. You can also register a RecurringJob which is reliant on the system Conjobs service to be running. 

### Register a job to be run right away:

In this example, lets say we have just created a new user, and want to schedule sending an email to the user. 
First you would create a job that is ment to be run. This can be done with the CLI:

```
php nraa new:job SendWelcomeEmail
```

or manually by creating the class in the /app/Jobs folder:

```
class SendWelcomeEmailJob {

    public function send(\App\Models\Users $user){
        // Implement a function to send and email
    }

}
```

```
$registrar = new JobRegistrar($queue, $scheduled);
$registrar->registerJob([SendWelcomeEmailJob::class, 'send'], ['user' => $user], null, 'UserService');
```

the parameters accepted by the method registerJob are:

- 1: An array with the parameters className and function or a callable 
- 2: An array with the parameters that will be passed to your function
- 3: A DateTime for specifying a given time you want the job to be scheduled
- 4: A name to connect to the scheduler of the job. This is just to make it easier to understand when implemented in an admin panel

```
public function registerJob(
    $callback,
    array $params = [],
    ?\DateTimeImmutable $runAt = null,
    ?string $employer = null
)
```

### Register a job to be run at a specific timepoint

In some cases it necessesary to send an email at a later time, like a reminder to to something 2 days after registering:

```
$registrar = new JobRegistrar($queue, $scheduled);
$registrar->registerJob([SendReminderEmailJob::class, 'send'], ['user' => $user], (new \DateTimeImmutable())->modify('+2 days'), 'UserService');
```

### Register a recurring job

Recurring jobs take use of the systems php cli to run jobs in the backend. Here you can schedule recurring jobs to be run. This can be heavier tasks that should be 
run no matter if the frontend is visited. This can be jobs such as regularly importing data from a API Service etc.

```
$recurring = new RecurringJobs();
$recurring->register([SteamServiceProvider::class, 'ImportSkins'], '*/10 * * * *');
```

## Filesystem

The framework supports writing to different types of filesystems such as Local storage, S3, Google Cloud Storage, FTP, SFTP 

To configure your desired filesystem, edit the app/config/filesystems.php to add desired adapters. 
You can configure multiple providers and set up multiple configurations for buckets on the same provider 

Example usage:

```
$filesInBucket = app()->getFilesystem('google_bucket1')->listContents('', true)->toArray();
```

The system is integrated on top of the package Flysystem. Documentatin of all available commands can be found at:
https://flysystem.thephpleague.com/docs/

getFilesystem() returns and instance of Flysystems Filesystem. for the given configuration

## Configuration and deployment

DOCKER EXPLANATION WILL COME HERE SOON 

