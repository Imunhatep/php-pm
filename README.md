PHP ProcessManager for Request-Response Applications
====================================================

This is a fork of [marcj/php-pm](https://github.com/marcj/php-pm). 

PHP-PM is a process manager for Request-Response Frameworks running in a [@RePHP](https://github.com/Imunhatep/rephp) environment. The approach of this is to kill the expensive bootstrap of php (declaring symbols) and bootstrap of feature-rich frameworks.

More information can be found in the article: [Bring High Performance Into Your PHP App (with ReactPHP)](http://marcjschmidt.de/blog/2014/02/08/php-high-performance.html)

## Install

The recommended way to install react is [through composer](http://getcomposer.org).

```JSON
{
    "repositories": [
        { "type": "vcs", "url": "http://github.com/Imunhatep/rephp" },
        { "type": "vcs", "url": "http://github.com/Imunhatep/php-pm" }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "imunhatep/php-pm": "@dev"
    }
}
```


### Command

```bash
./bin/ppm start --help
Usage:
 start [--bridge="..."] [--port[="..."]] [--workers[="..."]] [--bootstrap[="..."]] [--app-env[="..."]] [working-directory]

Arguments:
 working-directory     The working directory.  (default: "./")

Options:
 --bridge              The bridge we use to convert a ReactPHP-Request to your target framework.
 --port                Load-Balancer port. Default is 8080
 --workers             Worker count. Default is 8. Should be minimum equal to the number of CPU cores.
 --app-env             The that your application will use to bootstrap.
 --bootstrap           The class that will be used to bootstrap your application.
 --help (-h)           Display this help message.
 --quiet (-q)          Do not output any message.
 --verbose (-v|vv|vvv) Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)        Display this application version.
 --ansi                Force ANSI output.
 --no-ansi             Disable ANSI output.
 --no-interaction (-n) Do not ask any interactive question.
```

### Example

```bash
$ ./bin/ppm start ~/my/path/to/symfony/ --bridge=HttpKernel
```

### Or through placing ppm.json in root dir
``` json
{
	"bootstrap": "\\PHPPM\\Bootstraps\\Symfony",
	"bridge": "HttpKernel",
	"appenv": "dev",
	"workers": 8
}
```

All worker start a own HTTP Server which listens on port 5501, 5502, 5503 etc. Range is `5501 -> 5500+<workersCount>`.

### Setup 1. Use external Load-Balancer

![ReactPHP with external Load-Balancer](doc/reactphp-external-balancer.jpg)

Example config for NGiNX for 8 workers:

```nginx
upstream backend  {
    server 127.0.0.1:5501;
    server 127.0.0.1:5502;
    server 127.0.0.1:5503;
    server 127.0.0.1:5504;
    server 127.0.0.1:5505;
    server 127.0.0.1:5506;
    server 127.0.0.1:5507;
    server 127.0.0.1:5508;
}

server {
    root /path/to/symfony/web/;
    server_name servername.com;

    location / {
                #index index.php;

                # try to serve file directly, fallback to rewrite
                try_files $uri @rewriteapp;
        }

        location @rewriteapp {
                if (!-f $request_filename) {
                        proxy_pass http://backend;
                        break;
                }
        }
}

```

### Setup 2. Use internal Load-Balancer

This setup is slower as we can't load balance incoming connection as fast as NGiNX it does,
but it's perfect for testing purposes.

![ReactPHP with internal Load-Balancer](doc/reactphp-internal-balancer.jpg)
