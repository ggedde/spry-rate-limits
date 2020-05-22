# Spry Rate Limits
This is a Spry Provider for adding Rate Limits to your Routes or a global Rate Limit for all requests

In order to activate Rate Limits you need to initialze the Provider within your config and set the Rate Limit settings within your Config.  

### Spry Configuration Example
```php
$config->rateLimits = [
  'driver' => 'db',
  'table' => 'rate_limits'
];

Spry::addHook('initialized', 'Spry\\SpryProvider\\SpryRateLimits::initiate');
```
<br>

\* *By Default Rate Limits are not active, but you can set a global rate limit by adding the `default` settings to your Spry Config.*

### Add a Global Rate Limit

```php
$config->routeRateLimits = [
  'driver' => 'db',
  'table' => 'rate_limits',
  'default' => [
      'limit' => 10,
      'within' => 1,
      'by' => 'ip'
      'hook' => 'configure'
  ]
];
```

### Adding Limits per route  
```php
$config->routes = [
    '/auth/login' => [
        'label' => 'Auth Login',
        'controller' => 'Auth::login',
        'access' => 'public',
        'methods' => 'POST',
        'limits' => [
            'limit' => 1,
            'within' => 3,
            'by' => 'ip'
        ],
        'params' => [
            'email' => [
                'required' => true,
                'type' => 'string',
            ],
            'password' => [
                'required' => true,
                'type' => 'string',
            ],
        ],
    ],
];
``` 
### Settings

Setting | Type | Default | Description
-------|--------|-------------|-----------
driver | String | '' | Driver to use to store the Rate Limit History. Currently only `db` is allowed. `db` uses SpryDB Provider to store the data so you must have SpryDB configured. In the future we plan to add `files`, `memcached`, `redis`.
table | String | '' | When Driver is set to `db` you must set a Table to store the rate limit history.
default | Array | [] | Default Global Rate Limit settings.

### Rate Limit Settings

Setting | Type | Default | Description
-------|--------|-------------|-----------
limit | Number | 0 | Number of allowed requests
within | Number | 0 | Time in Seconds to allow.
by | String | '' | Key used to identify the request. By default SpryRateLimits only supports `ip`. However, you can hook into this field and filter it with your own value. ex.  `account_id` or `user_id`. See below for more details.
hook | String | 'setRoute' | When to run this Rate limiit. This uses Spry Hooks See ([Spry Lifecycles](https://github.com/ggedde/spry/blob/master/README.md#Lifecycle))




