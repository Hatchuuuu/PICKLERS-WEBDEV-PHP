const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('templates/web/owner-application.html.twig', 'utf8');
const gps = source.slice(source.indexOf('function detectGPSLocation('), source.indexOf('function triggerFileInput('));

for (const unavailable of [false, true]) test('application GPS failure preserves applicant data: ' + unavailable, () => {
  const elements = {
    address: { value: 'Actual court address' }, latitude: { value: '14.5' }, longitude: { value: '121.0' },
    gpsBtnText: { innerText: '' }, gpsStatus: { textContent: '' }
  };
  const navigator = unavailable ? {} : { geolocation: { getCurrentPosition: (ok, fail) => fail({ code: 1 }) } };
  const context = { navigator, document: { getElementById: id => elements[id] } };
  vm.createContext(context); vm.runInContext(gps, context); context.detectGPSLocation();
  assert.equal(elements.address.value, 'Actual court address');
  assert.equal(elements.latitude.value, '14.5');
  assert.equal(elements.longitude.value, '121.0');
  assert.match(elements.gpsStatus.textContent, /manually|unchanged/i);
  assert.doesNotMatch(elements.gpsBtnText.innerText, /✓/);
});

test('application geocoding failure uses measured coordinates, without inventing an address', async () => {
  const elements = {
    address: { value: 'Old address' }, latitude: {}, longitude: {}, gpsBtnText: {}, gpsStatus: {}
  };
  const context = {
    navigator: { geolocation: { getCurrentPosition: ok => ok({ coords: { latitude: -9.3, longitude: -123.3 } }) } },
    document: { getElementById: id => elements[id] }, fetch: async () => { throw new Error('offline'); }
  };
  vm.createContext(context); vm.runInContext(gps, context); context.detectGPSLocation();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(elements.address.value, '-9.3000, -123.3000');
  assert.equal(elements.latitude.value, '-9.3000');
  assert.equal(elements.longitude.value, '-123.3000');
});

test('required document controls support native keyboard activation and have visible labels', () => {
  for (const [id, label] of [['dropzonePermit', 'permitLabel'], ['dropzoneGovId', 'govIdLabel']]) {
    assert.match(source, new RegExp('<button type="button" class="capture-frame" id="' + id + '" aria-labelledby="' + label + '"'));
    assert.match(source, new RegExp('id="' + label + '"'));
  }
});

test('an application without a measured GPS location submits no invented coordinates', () => {
  for (const id of ['latitude', 'longitude']) {
    assert.ok(new RegExp('id="' + id + '" name="' + id + '" value=""').test(source));
  }
});

test('wizard moves focus to the updated step heading after validation succeeds', () => {
  let focused = false;
  const heading = { textContent: '', focus() { focused = true; } };
  const context = {
    currentStep: 1, STEP_NAMES: ['Facility Details', 'Business & Contact', 'Permits & ID'], RING_CIRCUMFERENCE: 170,
    document: { getElementById: id => id === 'stepName' ? heading : id === 'stepPanel1' ? { querySelectorAll: () => [] } : null,
      querySelectorAll: () => [{ classList: { toggle() {} } }, { classList: { toggle() {} } }, { classList: { toggle() {} } }] }
  };
  vm.createContext(context);
  vm.runInContext(source.slice(source.indexOf('function updateVerifyProgress('), source.indexOf('function selectSurface(')), context);
  context.goToStep(2);
  assert.equal(heading.textContent, 'Business & Contact');
  assert.equal(focused, true);
  assert.match(source, /id="stepName"[^>]*tabindex="-1"/);
});

test('application notices use shared accessible dialog lifecycle on load and success', () => {
  assert.match(source, /assets\/js\/ux-core\.js/);
  assert.match(source, /UX\.openDialog\('elevationModal', \{ dismissible: false \}\)/);
  assert.match(source, /UX\.openDialog\('ownerAppNoticeModal'\)/);
  assert.match(source, /UX\.closeDialog\('ownerAppNoticeModal'\)/);
});

test('implicit submission in earlier wizard steps advances through existing validation before document checks', () => {
  for (const currentStep of [1, 2]) {
    let nextStep;
    const context = { currentStep, goToStep: step => { nextStep = step; },
      document: { getElementById() { throw new Error('Earlier steps must not validate hidden upload controls'); } } };
    vm.createContext(context);
    vm.runInContext(source.slice(source.indexOf('function handleFormSubmit('), source.indexOf("    if (document.getElementById('elevationModal').style.display")), context);
    context.handleFormSubmit({ preventDefault() {} });
    assert.equal(nextStep, currentStep + 1);
  }
});

test('facility certification requires the applicant to select it and contact help avoids unsupported SMS promises', () => {
  const certification = source.match(/<input[^>]*id="terms_check"[^>]*>/)[0];
  assert.doesNotMatch(certification, /\bchecked\b/);
  assert.doesNotMatch(source, /instant booking SMS alerts/);
});
