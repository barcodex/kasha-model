# kasha-model

Model library is meant to be used in web applications built on top of Kasha framework.

It is a simple database abstraction layer that is not meant to compete with ORM libraries.

Still, it greatly simplifies working with database table using business objects that are instances of classes that extend Kasha\Model\Model.

## API

This base class (Kasha\Model\Model) contains a lot of code that is generic for any database table wrapper, such as CRUD operations, reflection, internationalisation of string-typed fields and stubs for postprocessing triggers.

Public methods (incomplete list):

| method | description|
|--------|------------|
| setTableName($tableName) | Sets the name of underlying table in the database |
| load($id) | Loads a data row from the underlying database table in the object |
| isValid() | Checks if model's data contains at least 'id' column |
| getData() | returns the full data that is stored internally at the moment |
| getID() | returns the value of 'id' column from the data that is stored internally at the moment |
| insert($fields) | inserts a new row into the database |
| update($fields) | updates currently loaded row in the database |
| delete() | deletes current row from the underlying database table |
| getList($searchParams) | returns the list of rows that match specified search parameters |
| getRow($searchParams) | returns the first row that match specified search parameters |
| exists($searchParams) | returns true if at least one row in underlying table matches the search paramerters |
| get($fieldName) | returns the value of the column of currently loaded row |

There are also some stubs for the methods that get very useful in your business classes that extend Kasha\Model\Model:

| method | description|
|--------|------------|
| getExtendedData() | allows to extended standard data (returned with getData() with more columns |
| onUpdate($id) | triggered after update() method is run |
| onInsert($id) | triggered after insert() method is run |
| onDelete($id) | triggered after delete() method is run |

## Conventions

Kasha assumes that database table have structure that follows some rules:

* there is at least 'id' field in the table. Even if table is a mapping, always add 'id' field
* there is a field called 'created' to mark the time when this row was created
* there are fields called 'updated' and 'editor' to mark the time of last update and the id of the user who did this edit

## Internals

The whole Kasha framework favours standard associative array of PHP - if you ever tried to use fetch_assoc method on mysql results, you know how such an array represents a row in the table.

Model subclasses are built on top of this array, which is known as 'data' internally. To get this array from any moment at any given time, just call getData() method.

Of course, model is a business object, which it means that whatever logic can be built on top of the data array - most notably adding new fields.

However, we strongly recommend to extend the raw data array within getExtendedData() method (which by default just wraps getData()).

One of the biggest wins of using Model subclasses is actually the postprocessing hooks functionality. You can implement your business logic (cascading deletes/updates, refreshing statistics, cleaning of the cache values, queueing messages, sending mails etc - you name it) in onDelete(), onInsert() on onUpdate() methods, and you can do that depending on the fields that were changed or even previous values of these fields.

For example, you can write a specific code which will be triggered only when field 'status' of your field changes from 'open' to 'progress', or when value of 'views' column exceeds 100.

For running simple updated on the fields, you just pass associative array with changed fields to update() method:

```php
$user = new User();
$user->load(3); // loads $user object with data fetched by given id=3
$user->update(array('first_name' => 'John', 'last_name' => 'Doe'));
```

## Caching

Model library for Kasha also supports caching.

Class Kasha\Model\Cache extends the standard Kasha\Caching\Cache with some handy methods for dealing with model data and metadata.

As a consumer of the library, you do not even need to know about the internals of caching-related classes, but you need to know where cached values are stored.

At the moment, the only supported cache storage is file system, so model-related cached values are stored under the same /app/cache folder as for everything else in Kasha framework.
Model component will make sure that metadata for the tables (files are called [tableName].txt) is stored under /app/cache/metadata, and json objects that correspond table row (optionally with more calculated data, according to the logic of business objects that extend Kasha\Model\Model) are stored in /app/cache/data and then in files named [id].txt sorted in folders named by the tables.

For example, if caching is enabled for Model library, and the script was working with the country identified by id=84, then after script runs, some new files appear in the /app/cache folder:

```
/app
 /cache
  /metadata
   /country.txt
  /models
   /country
    /84.txt
```

In theory, every model object can even have its own cache - it can be set using setCache() method - the only requirement is that provided object extends Model\Caching\Cache base class.

However, in most cases, using standard Kasha\Model\Cache is enough - and for that, you don't need to explicitly specify something. The only quirk of using the standard schema is that every Model's constructor method tries to set the cache object that is received via Kasha\Model\ModelConfig class.
In its turn, this class instantiates Kasha\Model\Cache using the same root folder path as standard Kasha\Caching\Cache, which means that it should be instantiated before ANY model is used.

Here's an example script to illustrate the point (we assume it is run in root folder of the application):

```php
$cache = new Cache(__DIR__ . '/app/cache/');

$country = new Country();
$list = $country->getList(array('iso2' => 'AZ'));
```

First line of this snippet makes sure that base cache class is instantiated for use by any further code in the script, including its own subclasses.

The constructor of Country class asks Kasha\Model\ModelConfig for an instance of the cache, which by default will be an instance of Kasha\Model\Cache, created on the same root folder as Kasha\Caching\Cache, instantiated in first line of the snippet.

This default behavour can also be overwritten by providing another Cache class if required
