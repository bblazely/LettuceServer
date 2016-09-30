#!/usr/bin/env node

var Http = require('http'),
    EventEmitter = require('events').EventEmitter,
    Fs = require('fs'),
    MemCached = require('memcached'),
    Amqp = require('amqplib/callback_api'),
    Crypto = require('crypto'),

    mc = new MemCached(["127.0.0.1:11212", "127.0.0.1:11214"]),
    ee = new EventEmitter(),
    socket = process.argv[2],
    RTU_PID = process.pid,
    http_server,
    stats = {
        clients: 0,
        queues: 0
    },
    conn_id_tracker = 0;
    AMQP_ADDRESS = 'amqp://localhost',
    RTU_REQUEST_TIMEOUT = 55000,
    RTU_KEY_TIMEOUT = 300,
    RTU_KEY_TIMEOUT_REFRESH = RTU_KEY_TIMEOUT * 0.85,   // Touch key at this point to prevent expiration
    CONN_ID_TRACKER_MAX = 10000,
    UID = 33,   // www-data
    GID = 33;   // www-data

if (!socket) {
    console.log('Error: No socket path specified.');
    process.exit();
}

//region HTTPServerSetup
http_server = Http.createServer(); console.log(RTU_PID, 'HTTP Server Created');

http_server.on('connection', function (c) {
    "use strict";
    stats.clients = stats.clients + 1;
    c.on('close', function () {
        stats.clients = stats.clients - 1;
    });
});

http_server.on('listening', function () {
    "use strict";
    console.log("\tListening on", socket);
    Fs.chown(socket, UID, GID);
});

http_server.on('error', function (e) {
    "use strict";

    if (e.code === 'EADDRINUSE') {
        Http.request({socketPath: socket}, function () {
            console.log(RTU_PID, "! Socket in use. Exiting.");
            process.exit();
        }).on('error', function () {
            try {
                Fs.unlinkSync(socket);
            } catch (e) {
                console.log(RTU_PID, "Error: Could not create socket", socket);
                process.exit();
            }
            http_server.listen(socket);
            console.log(RTU_PID, "Existing disconnected socket reused. Ready.");
        }).end();
    } else {
        console.log(RTU_PID, "Error: Unhandled exception:", e);
        process.exit();
    }
});
//endregion HTTPServerSetup

Amqp.connect(AMQP_ADDRESS, function (err, conn) {
    "use strict";

    console.log(RTU_PID, 'Connected to AMQP');
    conn.createChannel(function (err, ch) {
        var listener_pool = {},
            listener_timer_gc = {},
            listener_count = {};

        function registerListener(entity_id, listener) {
            if (!listener_pool[entity_id]) {
                listener_pool[entity_id] = [];
            }
            listener_pool[entity_id].push(listener);

            ee.emit('watch_entity', entity_id);

            return function () {
                //              console.log("X Client Disconnected from ", entity_id);
                var i = listener_pool[entity_id].indexOf(listener);
                listener_pool[entity_id][i] = null;
                listener_pool[entity_id].splice(i, 1);
                ee.emit('unwatch_entity', entity_id);
            };
        }

        ch.assertExchange('ChangeSet', 'topic', {durable: false});
        console.log(RTU_PID, "AMQP Exchange Asserted");

        ee.on('watch_entity', function (entity_id) {
            if (listener_timer_gc[entity_id]) {
                //console.log('recycle existing queue for ', entity_id);
                clearTimeout(listener_timer_gc[entity_id]);
                delete listener_timer_gc[entity_id];
            }

            if (listener_count[entity_id] === undefined) {
                listener_count[entity_id] = 1;

                //console.log(RTU_PID, 'Assert Queue for', RTU_PID, entity_id);
                ch.assertQueue(RTU_PID + '.' + entity_id, {
                    durable: false,
                    autoDelete: true
                    //'x-expires': QUEUE_MAX_IDLE
                }, function (err, q) {
                    stats.queues += 1;
                    ch.bindQueue(q.queue, 'ChangeSet', entity_id);
                    ch.consume(q.queue, function (msg) {
                        msg = JSON.parse(msg.content);  // Parse the JSON once
                        //console.log(RTU_PID, 'Event Received:', msg);

                        listener_pool[entity_id].forEach(function (listener) {
                            listener(entity_id, msg);
                        })
                    }, {consumerTag: entity_id});
                });
            } else {
                listener_count[entity_id]++;
                //console.log('Entity', entity_id, 'watched by', listener_count[entity_id]);
            }
        });

        ee.on('unwatch_entity', function (entity_id) {
            //console.log('unwatch', entity_id);
            if (listener_count[entity_id] > 0) {
                listener_count[entity_id]--;
                if (listener_count[entity_id] === 0) {
                    // De listen!
                 //   console.log('expire listener', entity_id);
                    listener_timer_gc[entity_id] = setTimeout(function () {
                        if (listener_count[entity_id] === 0) { // check it is still 0
                   //         console.log('delete listener', entity_id);
                            delete listener_timer_gc[entity_id];
                            delete listener_count[entity_id];
                            stats.queues -= 1;
                            ch.cancel(entity_id);
                            //delete listener_pool[entity_id];
                        }
                    }, 10000);
                }
            }
        });

        // Wait for client_web connection
        console.log(RTU_PID, "Waiting for Client Connection...");
        http_server.on('request', function (request, response) {
            var parts,
                rtu_key,
                start_timestamp,
                next_timestamp,
                seq, seq_rtu_max,
                local_listeners = {},
                conn_id = (conn_id_tracker++) % CONN_ID_TRACKER_MAX; // This might work?;

            function sendOutput(response_code, data, headers) {
                //console.log(conn_id, 'SEND:', response_code);
                if (headers) {
                    for (var header in headers) {
                        response.setHeader(header, headers[header]);
                    }
                }

                response.writeHead(response_code);

                if (next_timestamp && next_timestamp != start_timestamp) {
                    if (!data) {
                        data = {};
                    }
                    data.t = next_timestamp;
                }

                if (data) {
                    response.write(")]}',\n" + JSON.stringify(data));
                }
                response.end();
            }

            // Update delta key in memcache
            function touchRTUKey(rtu_key, rtu_key_timeout, callback) {
                //console.log(conn_id, 'touch RTU key');
                mc.touch('RTU.' + rtu_key, rtu_key_timeout, function (err, result) {
                    if (callback) {
                        if (result === true) {
                            callback(Date.now() / 1000);    // TODO is this right? / 1000
                        } else {
                            callback(null);
                        }
                    }
                });
            }

            function removeListeners() {
                for (var entity_id in local_listeners) {
                    //console.log(conn_id, 'remove local listener for ', entity_id);
                    local_listeners[entity_id]();
                }
            }

            function updateWatchList(rtu_key, watch_list_update, watch_list_current, callback) {
                var watch_key,
                    op_key,
                    entity_id,
                    assoc_id, i,
                    watch_list = (watch_list_current) ? watch_list_current : {};

                if (typeof watch_list_update == 'object' && Object.keys(watch_list_update).length != 0) {
                    for (watch_key in watch_list_update) {
                        if (watch_key === 'l') {
                            for (entity_id in watch_list_update.l) {
                                //noinspection JSUnfilteredForInLoop (JSON)
                                for (op_key in watch_list_update.l[entity_id]) {
                                    if (op_key === 'a') {   // Add
                                        if (!watch_list.l) {
                                            watch_list.l = {};
                                        }

                                        if (!watch_list.l[entity_id]) {
                                            watch_list.l[entity_id] = {};
                                        }

                                        for (assoc_id in watch_list_update.l[entity_id].a) {
                                            watch_list.l[entity_id][assoc_id] = parseInt(watch_list_update.l[entity_id].a[assoc_id]);
                                        }
                                    } else if (op_key === 'r') {
                                        for (i = 0; i < watch_list_update.l[entity_id].r.length; i++) {
                                            assoc_id = watch_list_update.l[entity_id].r[i];
                                            if (watch_list.l && watch_list.l[entity_id]) {
                                                delete watch_list.l[entity_id][assoc_id]
                                            }
                                        }


                                        // Clean up the watch list by removing empty nodes.
                                        if (watch_list.l && Object.keys(watch_list.l[entity_id]).length === 0) {
                                            delete watch_list.l[entity_id];
                                            if (Object.keys(watch_list.l).length === 0) {
                                                delete watch_list.l;
                                            }
                                        }

                                    }
                                }
                            }
                        } else if (watch_key === 'e') {
                            //noinspection JSUnfilteredForInLoop
                            for (op_key in watch_list_update.e) {
                                if (op_key === 'a') {   // Add Entity
                                    for (entity_id in watch_list_update.e.a) {
                                        if (!watch_list.e) {
                                            watch_list.e = {};
                                        }
                                        watch_list.e[entity_id] = watch_list_update.e.a[entity_id];
                                    }
                                } else if (op_key === 'r') { // Remove Entity
                                    for (i = 0; i < watch_list_update.e.r.length; i++) {
                                        if (watch_list.e) {
                                            delete watch_list.e[watch_list_update.e.r[i]];
                                        }
                                    }

                                    if (typeof watch_list == 'object' && watch_list.e && Object.keys(watch_list.e).length === 0) {
                                        delete watch_list.e;
                                    }
                                }
                            }
                        }
                    }

                    if (typeof watch_list == 'object' && Object.keys(watch_list).length > 0) {
                        mc.set('RTU.' + rtu_key, watch_list, 0, function (err) {
                            if (callback) {
                                callback(watch_list);
                            }
                        });
                    } else {
                        mc.del('RTU.' + rtu_key, function (err) {
                            if (callback) {
                                callback(watch_list);
                            }
                        });
                    }
                } else {
                    if (callback) {
                        callback(watch_list_current);
                    }
                }
            }

            function initWatch(rtu_key, watch_list_update) {
                if (!rtu_key || rtu_key === '0') {  // New RTU key needed
                    rtu_key = (new Buffer(Crypto.randomBytes(32)).toString('base64')).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
                    //console.log(RTU_PID, conn_id, 'RTU Init. Key Issued', rtu_key);
                    updateWatchList(rtu_key, watch_list_update, null, function (watch_list) {
                        // Return the new RTU key immediately
                        sendOutput(200, {
                            k: rtu_key
                        });
                    });
                } else {    // Grab data from specified RTU key
                    mc.get('RTU.' + rtu_key, function (err, watch_list_current) {
                        if (watch_list_update) {
                            updateWatchList(rtu_key, watch_list_update, watch_list_current, function (watch_list) {
                                doWatch(watch_list);
                            });
                        } else {
                            doWatch(watch_list_current);
                        }
                    });
                }
            }

            function doWatch(watch_list, issue_rtu_key) {
                var watch_key,
                    entity_id,
                    delta_keys = [],
                    timer_output, timer_request_timeout,
                    output;

                function triggerOutput() {
                    clearTimeout(timer_request_timeout);
                    clearTimeout(timer_output);

                    // Unless there is more output coming, transmit to front end.
                    timer_output = setTimeout(function () {
                        touchRTUKey(rtu_key, RTU_KEY_TIMEOUT, function () {
                            removeListeners();
                            //console.log(conn_id, rtu_key, "Sending To Client");

                            sendOutput(200, {
                                t: next_timestamp,
                                c: output
                            }, {
                                Expires: -1
                            });

                            //console.log(conn_id, rtu_key, "End\n");
                        });
                    }, 500);
                }

                if (watch_list && Object.keys(watch_list).length === 0) { // Nothing to watch
                    // Send Cancel Watch Output
                    sendOutput(204);
                } else {
                    for (watch_key in watch_list) {
                        for (entity_id in watch_list[watch_key]) {

                            // Register a delta entry for loading after listeners are registered
                            delta_keys.push('Delta.' + entity_id);

                            // Step 2: Register a listener against the shared queue for this connection
                            //         Track it locally so that it can be easily de-allocated.
                            if (!local_listeners[entity_id]) {
                                local_listeners[entity_id] = registerListener(entity_id, processMessage);
                            }
                        }
                    }

                    // Step 3: Get any initial Delta Update keys for all watched entities to ensure nothing slipped through during transition
                    mc.getMulti(
                        delta_keys,
                        function (err, data) {
//                            console.log(conn_id, 'DKR: ', data);
                            if (data) {
                                Object.keys(data).forEach(function (key) {
                                    data[key].split("\n").forEach(function (delta) {
                                        processMessage(key.substr(6), JSON.parse(delta));
                                    });
                                });
                            }
                            // delta_checked = true;
                        }
                    );

                    // Kill the request after RTU_REQUEST_TIMEOUT / 1000 seconds.
                    timer_request_timeout = setTimeout(function () {
                        //console.log(conn_id, 'timeout, 55s');
                        clearTimeout(timer_output);
                        removeListeners();
                        sendOutput(200);
                    }, RTU_REQUEST_TIMEOUT);
                }

                // Defined here for access to the watch_list.
                function processMessage(entity_id, msg) {
                    var assoc_list;

                    //console.log(conn_id, rtu_key, "Process Message for", entity_id, JSON.stringify(msg));
                    if (msg.l) {
                      //  console.log(conn_id, rtu_key, "Found Assoc List Entry\n");
                        assoc_list = msg.l;
                        //console.log(assoc_list);
                        Object.keys(assoc_list).forEach(function (assoc_id) {
                            /*                            console.log('process key', assoc_id);
                             console.log('watch_list.l?', (watch_list.l) ? true : false);
                             console.log('watch_list.l[entity_id]?', (watch_list.l[entity_id]) ? true : false);
                             console.log('watch_list.l[entity_id][assoc_id]?', (assoc_id in watch_list.l[entity_id]) ? true : false);
                             console.log(watch_list.l[entity_id]);*/
                            if (watch_list.l && watch_list.l[entity_id] && (assoc_id in watch_list.l[entity_id])) { // watching for this assoc?
                             //   console.log('assoc is watched');
                                Object.keys(assoc_list[assoc_id]).forEach(function (change) {
                               //     console.log('assoc change: ', change);
                                    Object.keys(assoc_list[assoc_id][change]).forEach(function (entity_id_applied) {
                                 //       console.log('assoc_id_applied: ', entity_id_applied);
                                        var change_timestamp = assoc_list[assoc_id][change][entity_id_applied];
                                        if (change_timestamp > start_timestamp && change_timestamp > watch_list.l[entity_id][assoc_id]) {
                                            if (!output) {
                                                output = {};
                                            }

                                            if (!output[entity_id]) {
                                                output[entity_id] = {'l': {}}
                                            }

                                            if (!output[entity_id].l) {
                                                output[entity_id].l = {};
                                            }

                                            if (!output[entity_id].l[assoc_id]) {
                                                output[entity_id].l[assoc_id] = {};
                                            }

                                            if (!output[entity_id].l[assoc_id][change]) {
                                                output[entity_id].l[assoc_id][change] = [];
                                            }

                                            output[entity_id].l[assoc_id][change].push(entity_id_applied);
                                            if (change_timestamp > next_timestamp) {
                                                //console.log(conn_id, rtu_key, "Bump Next Timestamp:", change_timestamp);
                                                next_timestamp = change_timestamp;  // Move the delta timestamp up
                                            }

                                            triggerOutput();

                                            //console.log(conn_id, rtu_key, "Processed assoc_id", assoc_id, 'for entity', entity_id);
                                        } /*else {
                                            console.log(conn_id, rtu_key, "Change in assoc_id", assoc_id, 'for entity', entity_id, "is stale. Ignored\n");
                                        }*/


                                    });
                                });
                            }
                            /*else {
                             console.log('\t\t# Ignore assoc_id', assoc_id, 'for entity', entity_id);
                             }*/
                        });
                    }

                    if (msg.e && watch_list.e && watch_list.e[entity_id]) {
                      //  console.log(conn_id, rtu_key, "Found Entity ID Entry\n");

                        if (msg.e > start_timestamp && msg.e > watch_list.e[entity_id]) {
                            if (msg.e > next_timestamp) {
                             //   console.log(conn_id, rtu_key, "Bump Next Timestamp:", msg.e);
                                next_timestamp = msg.e;
                            }

                            if (!output) {
                                output = {};
                            }

                            if (!output[entity_id]) {
                                output[entity_id] = {};
                            }

                            output[entity_id].e = msg.e;

                            triggerOutput();
                          //  console.log(conn_id, rtu_key, "Processed entity_id change", entity_id, "\n");
                        } /*else {
                            console.log(conn_id, rtu_key, "Entity Change for", entity_id, "is stale. Ignored\n");
                        }*/
                    }
                }
            }

            if (request.url.indexOf('stats') !== -1) {
                console.log(RTU_PID, "Client Connected (Stats Request)");
                sendOutput(200, {stats: stats, queues: listener_count, mem: process.memoryUsage()});
            } else {
                parts = request.url.substr(1).split('/');
                rtu_key = parts[0];
                start_timestamp = parseInt(parts[1]);
                next_timestamp = start_timestamp;

                seq = parseInt(parts[2]);
                seq_rtu_max = Math.floor((RTU_KEY_TIMEOUT_REFRESH * 1000) / RTU_REQUEST_TIMEOUT) + 1;

                if ( seq % seq_rtu_max === 0) {
                    touchRTUKey(rtu_key, RTU_REQUEST_TIMEOUT);
                }

                if (request.method === 'POST') {
                    processRequest(request, response, function () {
                        initWatch(rtu_key, request.post.watch);
                    });
                } else {
                    initWatch(rtu_key);
                }
            }
        });
    });
});

function processRequest(request, response, callback) {
    var queryData = "";
    if(typeof callback !== 'function') return null;

    if(request.method == 'POST') {
        request.on('data', function(data) {
            queryData += data;
            if(queryData.length > 1e6) {
                queryData = "";
                response.writeHead(413, {'Content-Type': 'text/plain'}).end();
                request.connection.destroy();
            }
        });

        request.on('end', function() {
            request.post = JSON.parse(queryData);
            callback();
        });

    } else {
        response.writeHead(405, {'Content-Type': 'text/plain'});
        response.end();
    }
}

process.setgid('www-data');
http_server.listen(socket);