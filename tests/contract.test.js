#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const files = [
  'includes/class-plugin.php',
  'includes/class-settings.php',
  'includes/class-platform-client.php',
  'includes/class-express-client.php',
  'assets/js/delivery-adapter.js',
  'assets/js/pickup-widget.js',
  'assets/js/courier-widget.js',
].map((file) => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');

assert(!/glow[\s_-]?me|goflow/i.test(files), 'Legacy product identifiers must not ship.');
assert(files.includes('ProviderStateStore'), 'Server-owned provider state must be integrated.');
assert(files.includes('createDeliveryStateMachine'), 'Each selected rate must use the bundled state machine.');
assert(files.includes('createCheckoutBridge'), 'Woo checkout refresh must use the shared bridge contract.');
assert(files.includes('ranau_yandex_delivery_settings'), 'Credentials must belong to this standalone plugin.');
assert(!/SOURCE_STATION_ID|WAREHOUSE_ADDRESS|WAREHOUSE_LAT|WAREHOUSE_LON/.test(files), 'Store-specific source identifiers must not be hard-coded.');
assert(!/permission_callback'\s*=>\s*'__return_true'/.test(files), 'Mutating REST routes must require a nonce.');

process.stdout.write('Ranau Yandex Delivery contract checks passed.\n');
