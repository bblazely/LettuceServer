# Lettuce Server

I was inspired after reading a [blog post](https://www.facebook.com/notes/facebook-engineering/tao-the-power-of-the-graph/10151525983993920/) on Facebook’s TAO architecture
and decided to try to build an entity association API of my own as a learning experience. It is largely incomplete.

It’s mostly written in PHP, using MySQL (via PDO) for storage and memcached as a reasonably transparent (at least to the entity module developer) write-back cache. I've also experimented with RabbitMQ in places (sending requests to the mailer daemon for example). 

There is a node.js backend included as a long-poll end point for the messaging queue that I started on, but haven't finished.

There are two partial SPA front ends for this project: 
* Initially based on [AngularJS 1.x](https://github.com/bblazely/LettuceClient-AngularJS1)
* A **very** early port to [Aurelia](https://github.com/bblazely/LettuceClient-Aurelia)


**Note:** This code may break without notice.
