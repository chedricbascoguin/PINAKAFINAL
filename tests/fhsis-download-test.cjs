const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const assert = require('node:assert/strict');
async function scenario(response) {
    let handler, prevented = false, downloaded = false;
    const button = {disabled:false,textContent:'Generate DOH Report'};
    const status = {textContent:''};
    const form = {matches:()=>true,querySelector:()=>button,nextElementSibling:status,
        action:{name:'action',value:'generate_doh_report'},
        getAttribute:name=>name==='action'?'RHUAdminDashboard.php?tab=reports':null};
    const link = {click(){downloaded=true;},remove(){}};
    runInNewContext(readFileSync('assets/js/fhsis-download.js','utf8'), {
        window:{}, document:{addEventListener(type,fn){handler=fn;},createElement(){return link;},body:{appendChild(){}}},
        fetch:async endpoint=>{assert.equal(endpoint,'RHUAdminDashboard.php?tab=reports');return response;},FormData:class {},URL:{createObjectURL:()=> 'blob:test',revokeObjectURL(){}},setTimeout(){}
    });
    await handler({target:form,preventDefault(){prevented=true;}});
    assert.equal(prevented,true); assert.equal(button.disabled,false);
    return {status:status.textContent,downloaded,link};
}
(async()=>{
    let result=await scenario({ok:true,headers:new Map([['Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],['Content-Disposition','attachment; filename="FHSIS-2026-10.xlsx"']]),blob:async()=>({size:100})});
    assert.equal(result.downloaded,true);assert.equal(result.link.download,'FHSIS-2026-10.xlsx');
    result=await scenario({ok:false,headers:new Map([['Content-Type','text/plain']]),text:async()=> 'Template missing'});
    assert.equal(result.downloaded,false);assert.equal(result.status,'Template missing');
    result=await scenario({ok:true,headers:new Map([['Content-Type','text/html']])});
    assert.equal(result.downloaded,false);assert.match(result.status,/session may have expired/);
    console.log('Passed: direct download, dashboard navigation prevented, inline server errors, expired-login response and button recovery.');
})().catch(e=>{console.error(e);process.exitCode=1;});
