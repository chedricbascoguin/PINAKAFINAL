const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/staff-session-lock.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function harness() {
    const classes = new Set(), events = {}, redirects = [], requests = [], timers = new Map();
    let timerId = 0;
    const fetch = () => new Promise(resolve => requests.push(state => resolve({ok:true,status:200,json:async()=>state})));
    const context = {
        document: {hidden:false, head:{appendChild(){}}, createElement:()=>({}),
            documentElement:{classList:{add:name=>classes.add(name),remove:name=>classes.delete(name)}},
            addEventListener:(name,handler)=>events[name]=handler},
        location:{pathname:'/NurseDashboard.php',search:'?tab=overview',replace:url=>redirects.push(url)},
        window:{fetch,addEventListener:(name,handler)=>events[name]=handler}, fetch,
        setInterval(){},setTimeout:callback=>{timers.set(++timerId,callback);return timerId;},
        clearTimeout:id=>timers.delete(id), URLSearchParams,Date,
    };
    vm.runInNewContext(source,context);
    return {classes,events,redirects,requests,timers};
}
const unlocked = {authenticated:true,locked:false,csrf:'token',timeout:300,server_time:1000,last_activity:1000};
(async()=>{
    const page = harness();
    // The original request is still pending when the browser restores the page.
    page.events.pagehide();
    page.events.pageshow({persisted:true});
    assert.equal(page.requests.length,2,'Restore must start a fresh access check');
    assert(page.classes.has('staff-lock-checking'));
    page.requests[1](unlocked);
    await flush();
    assert(!page.classes.has('staff-lock-checking'),'Restored dashboard remained blank');
    page.requests[0]({authenticated:true,locked:true});
    await flush();
    assert.equal(page.redirects.length,0,'Stale pre-navigation response redirected the restored dashboard');
    page.events.visibilitychange();
    assert(!page.classes.has('staff-lock-checking'),'Switching apps hid a still-valid dashboard');
    const previousRequestCount = page.requests.length;
    page.events.visibilitychange();
    assert.equal(page.requests.length,previousRequestCount+1,'App return reused a suspended request');
    page.requests[previousRequestCount](unlocked);
    await flush();
    page.requests[previousRequestCount-1]({authenticated:true,locked:true});
    await flush();
    assert.equal(page.redirects.length,0,'Stale app-switch response redirected the dashboard');
    // A previous lock redirect must not prevent a fresh check after Back.
    const redirect = harness();
    redirect.requests[0]({authenticated:true,locked:true});
    await flush();
    redirect.events.pageshow({persisted:true});
    redirect.requests[1](unlocked);
    await flush();
    assert(!redirect.classes.has('staff-lock-checking'),'Old redirect flag kept dashboard hidden');
    // A hung request must reach the lock screen instead of staying white forever.
    const stalled = harness();
    stalled.events.pageshow({persisted:true});
    [...stalled.timers.values()][0]();
    assert.equal(stalled.redirects.length,1,'Stalled check did not redirect');
    assert(stalled.redirects[0].startsWith('StaffLock.php?return='));
    console.log('History restore checks passed: pending requests, stale responses, old redirects, and stalled checks.');
})().catch(error=>{console.error(error);process.exitCode=1;});
