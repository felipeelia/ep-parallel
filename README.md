ElasticPress Parallel
====

> A proof of concept of a parallel index for ElasticPress.

[ElasticPress](https://github.com/10up/ElasticPress) index process is heavily dependent on I/O processes between the WordPress website and the Elasticsearch server. Using [Amp](https://amphp.org/) and their [Http Client](https://amphp.org/http-client/), this plugin is an attempt of making things faster using some parallelism.

**ATTENTION:** This is just a proof of concept and should NOT be used in production.

## Installation

1. Clone this repository or download the code
2. Run `npm run start` to install Node and Composer packages
3. Activate the plugin

## Usage

Instead of using ElasticPress WP-CLI command to index your content, use

```
wp ep-parallel index
```

It accepts all existent parameters of [the original command](http://10up.github.io/ElasticPress/tutorial-wp-cli.html).

## Preliminary results

This is a far-from-final result test but with a 15k posts database, what took `3m36sec` without the plugin, took only `1m35sec` with it.
