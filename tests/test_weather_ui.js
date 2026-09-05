// Node-only tests: load UI helpers without a browser or network.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const context = {
  window: {location: {origin: 'http://test.invalid'}},
  document: {addEventListener() {}},
  URL, Number, Date, console
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../homepage/static/ui-helpers.js'), 'utf8'), context);
const ui = context.window.BirdNETUI;
assert.equal(ui.weatherTemperature(null), '—');
assert.equal(ui.weatherTemperature(undefined), '—');
assert.equal(ui.weatherTemperature(''), '—');
assert.equal(ui.weatherTemperature(0), '0°');
assert.equal(ui.weatherTemperature(-10, '°C'), '-10°C');
assert.equal(ui.weatherEmoji(null, 1), '');
assert.equal(ui.weatherEmoji(0, null), '○');
assert.equal(ui.weatherEmoji(0, 0), '🌙');
assert.equal(ui.weatherSummary({temp: null, condition_code: null, wind_speed: 12, wind_unit: 'mph'}), 'Wind 12 mph');
assert.equal(ui.weatherSummary({temp: 0, temp_unit: '°C', condition_code: 0, condition: 'Clear'}), '0°C Clear');
assert.equal(ui.weatherSummary({temp: null, condition_code: null}), 'Weather unavailable');
console.log('11 weather UI checks passed');
