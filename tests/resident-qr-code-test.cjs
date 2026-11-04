const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const qrContext = {navigator:{userAgent:'Node test'},document:{documentElement:{tagName:'html'}}};
vm.createContext(qrContext);
vm.runInContext(fs.readFileSync(path.join(root,'assets/vendor/qrcode.min.js'),'utf8'),qrContext);
const decoderContext = {Uint8ClampedArray};
vm.runInNewContext(fs.readFileSync(path.join(root,'assets/vendor/jsQR.js'),'utf8'),decoderContext);
const jsQR = decoderContext.jsQR;
for (const url of ['https://rhu.example/StaffResidentAppointments.php?resident_id=1','https://rhu.example/portal/StaffResidentAppointments.php?resident_id=123456']) {
  const qr = new qrContext.QRCode({}, {correctLevel:qrContext.QRCode.CorrectLevel.M});
  qr._oDrawing.draw = () => {};
  qr.makeCode(url);
  const model = qr._oQRCode;
  const moduleCount = model.getModuleCount(), scale = 5, margin = 4;
  const side = (moduleCount + margin * 2) * scale;
  const pixels = new Uint8ClampedArray(side * side * 4).fill(255);
  for (let y=0;y<side;y++) for (let x=0;x<side;x++) {
    const row=Math.floor(y/scale)-margin, col=Math.floor(x/scale)-margin;
    if (row>=0 && col>=0 && row<moduleCount && col<moduleCount && model.isDark(row,col)) {
      const i=(y*side+x)*4; pixels[i]=pixels[i+1]=pixels[i+2]=0;
    }
  }
  assert.equal(jsQR(pixels,side,side)?.data,url,'Generated resident QR must decode to exact appointment URL');
}
async function scan(value, embedded = false) {
  const nodes = new Map(); let destination = null;
  const context = {drawImage(){},getImageData(){return {data:[],width:100,height:100};}};
  function node(id) {
    if (!nodes.has(id)) nodes.set(id,{readyState:2,videoWidth:100,videoHeight:100,async play(){},events:{},addEventListener(name,fn){this.events[name]=fn;},getContext(){return context;}});
    return nodes.get(id);
  }
  class TestURL extends URL { static createObjectURL(){return 'blob:test';} static revokeObjectURL(){} }
  const sandbox = {URL:TestURL,Image:class {naturalWidth=100;naturalHeight=100;async decode(){}},jsQR:()=>({data:value}),location:{href:'https://rhu.example/portal/StaffResidentAppointments.php' + (embedded ? '?embed=1' : ''),assign(url){destination=url;}},navigator:{mediaDevices:{async getUserMedia(){return {getTracks(){return [{stop(){}}];}};}}},document:{getElementById:node,addEventListener(){}},window:{addEventListener(){}},setTimeout(callback){if (!sandbox.testTick) {sandbox.testTick=true; callback();} return 1;},clearTimeout(){}};
  vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/resident-qr-scanner.js'),'utf8'),sandbox);
  await node('start-camera').events.click();
  return destination;
}
(async () => {
  const expected='https://rhu.example/portal/StaffResidentAppointments.php?resident_id=7';
  assert.equal(await scan(expected),expected);
  assert.equal(await scan('https://ruralhealthunit.page.gd/StaffResidentAppointments.php?resident_id=7'),expected,'Official hosted ID should resolve within the current portal');
  assert.equal(await scan('http://rhu.example/portal/StaffResidentAppointments.php?resident_id=7'),expected,'HTTP/HTTPS difference should not invalidate the resident ID');
  for (const host of ['resihunitynasugbu.online','resihunitynasugbu.site','www.resihunitynasugbu.online']) {
    assert.equal(await scan('https://'+host+'/StaffResidentAppointments.php?resident_id=7'),expected,'Hostinger resident IDs must open in the current staff portal');
  }
  assert.equal(await scan('https://resihunitynasugbu.online.evil.example/StaffResidentAppointments.php?resident_id=7'),null,'Lookalike domains remain blocked');
  assert.equal(await scan(expected, true), expected+'&embed=1', 'Scanned appointments must stay in the floating scanner');
  for (const value of ['https://evil.example/portal/StaffResidentAppointments.php?resident_id=7','javascript:alert(1)','https://rhu.example/StaffResidentAppointments.php?resident_id=7','https://rhu.example/portal/StaffResidentAppointments.php?resident_id=-1']) assert.equal(await scan(value),null,'Untrusted QR navigation blocked');
  assert.equal(await scan(expected+'&department=ADMIN'),expected,'QR cannot choose scanning role');
  console.log('QR round-trip and scanner navigation checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
