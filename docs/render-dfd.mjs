import fs from 'node:fs';

const nodes = {
  "A": [
    133,
    12,
    110,
    56,
    "entity",
    [
      "RHU",
      "ADMINISTRATOR"
    ]
  ],
  "P1": [
    236,
    116,
    147,
    65,
    "process",
    [
      "Manage User Registration",
      "and Resident Profile"
    ]
  ],
  "P6": [
    416,
    116,
    120,
    65,
    "process",
    [
      "Generate Reports and",
      "Monitoring Outputs"
    ]
  ],
  "R": [
    83,
    243,
    110,
    55,
    "entity",
    [
      "PATIENT /",
      "RESIDENT"
    ]
  ],
  "D4": [
    327,
    248,
    92,
    29,
    "store",
    [
      "User Accounts"
    ]
  ],
  "D1": [
    273,
    287,
    92,
    28,
    "store",
    [
      "Resident Record"
    ]
  ],
  "D3": [
    497,
    319,
    92,
    29,
    "store",
    [
      "Consultation / Service",
      "Transaction Log"
    ]
  ],
  "P3": [
    754,
    206,
    119,
    65,
    "process",
    [
      "Predict Health Service",
      "Demand / Patient",
      "Follow-up Needed"
    ]
  ],
  "P4": [
    228,
    390,
    119,
    64,
    "process",
    [
      "Process Service Request",
      "and Send Notification"
    ]
  ],
  "D2": [
    497,
    394,
    92,
    28,
    "store",
    [
      "Health Service",
      "Requests"
    ]
  ],
  "P2": [
    701,
    515,
    119,
    64,
    "process",
    [
      "Receive Health Service",
      "Requests and",
      "Validate / Approve"
    ]
  ],
  "W": [
    332,
    545,
    110,
    55,
    "entity",
    [
      "RHU PERSONNEL /",
      "HEALTH WORKER"
    ]
  ],
  "P5": [
    154,
    624,
    119,
    65,
    "process",
    [
      "Compute Location-Based",
      "Route / Service Schedule"
    ]
  ],
  "P7": [
    368,
    700,
    119,
    64,
    "process",
    [
      "Record Service Outcomes",
      "and RHU Services",
      "Coordination"
    ]
  ]
};
const flows = [
  {
    "from": "A",
    "to": "P1",
    "path": [
      [
        195,
        68
      ],
      [
        195,
        134
      ],
      [
        236,
        134
      ]
    ],
    "label": "Resident Updates",
    "labelAt": [
      198,
      92
    ],
    "lines": [
      "Resident",
      "Updates"
    ]
  },
  {
    "from": "P1",
    "to": "A",
    "path": [
      [
        236,
        155
      ],
      [
        160,
        155
      ],
      [
        160,
        68
      ]
    ],
    "label": "Update Confirmation",
    "labelAt": [
      160,
      118
    ],
    "lines": [
      "Update",
      "Confirmation"
    ]
  },
  {
    "from": "R",
    "to": "P1",
    "path": [
      [
        145,
        243
      ],
      [
        145,
        174
      ],
      [
        236,
        174
      ]
    ],
    "label": "Registration & Profile Updates",
    "labelAt": [
      145,
      207
    ],
    "lines": [
      "Registration &",
      "Profile Updates"
    ]
  },
  {
    "from": "P1",
    "to": "D4",
    "path": [
      [
        337,
        181
      ],
      [
        337,
        248
      ]
    ],
    "label": "Account Credentials and User Role",
    "labelAt": [
      337,
      212
    ],
    "lines": [
      "Account Credentials",
      "and User Role"
    ]
  },
  {
    "from": "P1",
    "to": "D1",
    "path": [
      [
        291,
        181
      ],
      [
        291,
        287
      ]
    ],
    "label": "Resident Profile Record",
    "labelAt": [
      291,
      237
    ],
    "lines": [
      "Resident Profile",
      "Record"
    ]
  },
  {
    "from": "A",
    "to": "P6",
    "path": [
      [
        243,
        26
      ],
      [
        500,
        26
      ],
      [
        500,
        116
      ]
    ],
    "label": "Report Request Parameters",
    "labelAt": [
      380,
      24
    ],
    "lines": [
      "Report Request Parameters"
    ]
  },
  {
    "from": "P6",
    "to": "A",
    "path": [
      [
        478,
        116
      ],
      [
        478,
        58
      ],
      [
        243,
        58
      ]
    ],
    "label": "System Reports",
    "labelAt": [
      357,
      55
    ],
    "lines": [
      "System Reports"
    ]
  },
  {
    "from": "D1",
    "to": "P6",
    "path": [
      [
        365,
        301
      ],
      [
        462,
        301
      ],
      [
        462,
        181
      ]
    ],
    "label": "Resident Summary",
    "labelAt": [
      421,
      295
    ],
    "lines": [
      "Resident",
      "Summary"
    ]
  },
  {
    "from": "D3",
    "to": "P6",
    "path": [
      [
        503,
        319
      ],
      [
        503,
        181
      ]
    ],
    "label": "Transaction Log",
    "labelAt": [
      503,
      247
    ],
    "lines": [
      "Transaction",
      "Log"
    ]
  },
  {
    "from": "P3",
    "to": "P6",
    "path": [
      [
        873,
        222
      ],
      [
        885,
        222
      ],
      [
        885,
        149
      ],
      [
        536,
        149
      ]
    ],
    "label": "Predicted Demand / Follow-up Summary",
    "labelAt": [
      705,
      146
    ],
    "lines": [
      "Predicted Demand / Follow-up Summary"
    ]
  },
  {
    "from": "D1",
    "to": "P3",
    "path": [
      [
        365,
        309
      ],
      [
        385,
        309
      ],
      [
        385,
        365
      ],
      [
        815,
        365
      ],
      [
        815,
        271
      ]
    ],
    "label": "Resident Health Attributes",
    "labelAt": [
      694,
      362
    ],
    "lines": [
      "Resident Health Attributes"
    ]
  },
  {
    "from": "D3",
    "to": "P3",
    "path": [
      [
        589,
        337
      ],
      [
        783,
        337
      ],
      [
        783,
        271
      ]
    ],
    "label": "Consultation / Service History",
    "labelAt": [
      689,
      334
    ],
    "lines": [
      "Consultation / Service History"
    ]
  },
  {
    "from": "R",
    "to": "P4",
    "path": [
      [
        176,
        298
      ],
      [
        176,
        430
      ],
      [
        228,
        430
      ]
    ],
    "label": "Health Service Request / Concern",
    "labelAt": [
      176,
      341
    ],
    "lines": [
      "Health Service",
      "Request / Concern"
    ]
  },
  {
    "from": "D1",
    "to": "P4",
    "path": [
      [
        291,
        315
      ],
      [
        291,
        390
      ]
    ],
    "label": "Resident Contact Details",
    "labelAt": [
      291,
      350
    ],
    "lines": [
      "Resident Contact",
      "Details"
    ]
  },
  {
    "from": "P4",
    "to": "D2",
    "path": [
      [
        347,
        403
      ],
      [
        410,
        403
      ],
      [
        410,
        378
      ],
      [
        525,
        378
      ],
      [
        525,
        394
      ]
    ],
    "label": "Submitted Request",
    "labelAt": [
      444,
      375
    ],
    "lines": [
      "Submitted Request"
    ]
  },
  {
    "from": "D2",
    "to": "P4",
    "path": [
      [
        497,
        411
      ],
      [
        347,
        411
      ]
    ],
    "label": "Request Decision / Service Status",
    "labelAt": [
      423,
      408
    ],
    "lines": [
      "Request Decision /",
      "Service Status"
    ]
  },
  {
    "from": "P4",
    "to": "R",
    "path": [
      [
        228,
        445
      ],
      [
        117,
        445
      ],
      [
        117,
        298
      ]
    ],
    "label": "Health Service Notification",
    "labelAt": [
      117,
      389
    ],
    "lines": [
      "Health Service",
      "Notification"
    ]
  },
  {
    "from": "D2",
    "to": "P2",
    "path": [
      [
        525,
        422
      ],
      [
        525,
        468
      ],
      [
        731,
        468
      ],
      [
        731,
        515
      ]
    ],
    "label": "Pending Request Record",
    "labelAt": [
      636,
      465
    ],
    "lines": [
      "Pending Request Record"
    ]
  },
  {
    "from": "W",
    "to": "P2",
    "path": [
      [
        442,
        560
      ],
      [
        474,
        560
      ],
      [
        474,
        531
      ],
      [
        701,
        531
      ]
    ],
    "label": "Approval Decision / Remarks",
    "labelAt": [
      581,
      528
    ],
    "lines": [
      "Approval Decision / Remarks"
    ]
  },
  {
    "from": "P2",
    "to": "D2",
    "path": [
      [
        789,
        515
      ],
      [
        789,
        447
      ],
      [
        562,
        447
      ],
      [
        562,
        422
      ]
    ],
    "label": "Approval / Rejection Status",
    "labelAt": [
      675,
      444
    ],
    "lines": [
      "Approval / Rejection Status"
    ]
  },
  {
    "from": "P4",
    "to": "W",
    "path": [
      [
        287,
        454
      ],
      [
        287,
        572
      ],
      [
        332,
        572
      ]
    ],
    "label": "Service Coordination Details",
    "labelAt": [
      287,
      501
    ],
    "lines": [
      "Service Coordination",
      "Details"
    ]
  },
  {
    "from": "R",
    "to": "P5",
    "path": [
      [
        83,
        271
      ],
      [
        41,
        271
      ],
      [
        41,
        655
      ],
      [
        154,
        655
      ]
    ],
    "label": "Location Input",
    "labelAt": [
      41,
      427
    ],
    "lines": [
      "Location Input"
    ]
  },
  {
    "from": "D2",
    "to": "P5",
    "path": [
      [
        510,
        422
      ],
      [
        510,
        488
      ],
      [
        300,
        488
      ],
      [
        300,
        611
      ],
      [
        220,
        611
      ],
      [
        220,
        624
      ]
    ],
    "label": "Approved Requests / Schedule Inputs",
    "labelAt": [
      395,
      485
    ],
    "lines": [
      "Approved Requests / Schedule Inputs"
    ]
  },
  {
    "from": "P5",
    "to": "W",
    "path": [
      [
        273,
        663
      ],
      [
        349,
        663
      ],
      [
        349,
        600
      ]
    ],
    "label": "Route / Schedule Result",
    "labelAt": [
      317,
      660
    ],
    "lines": [
      "Route / Schedule",
      "Result"
    ]
  },
  {
    "from": "P5",
    "to": "D2",
    "path": [
      [
        273,
        679
      ],
      [
        590,
        679
      ],
      [
        590,
        437
      ],
      [
        580,
        437
      ],
      [
        580,
        422
      ]
    ],
    "label": "Scheduled Request Details",
    "labelAt": [
      590,
      614
    ],
    "lines": [
      "Scheduled Request",
      "Details"
    ]
  },
  {
    "from": "P2",
    "to": "P7",
    "path": [
      [
        820,
        556
      ],
      [
        845,
        556
      ],
      [
        845,
        736
      ],
      [
        487,
        736
      ]
    ],
    "label": "Approved Request Details",
    "labelAt": [
      665,
      733
    ],
    "lines": [
      "Approved Request Details"
    ]
  },
  {
    "from": "W",
    "to": "P7",
    "path": [
      [
        414,
        600
      ],
      [
        414,
        700
      ]
    ],
    "label": "Service Status / Consultation Details",
    "labelAt": [
      435,
      626
    ],
    "lines": [
      "Service Status /",
      "Consultation Details"
    ]
  },
  {
    "from": "P7",
    "to": "W",
    "path": [
      [
        368,
        718
      ],
      [
        350,
        718
      ],
      [
        350,
        610
      ],
      [
        365,
        610
      ],
      [
        365,
        600
      ]
    ],
    "label": "Service Coordination",
    "labelAt": [
      350,
      693
    ],
    "lines": [
      "Service Coordination"
    ]
  },
  {
    "from": "P7",
    "to": "D3",
    "path": [
      [
        487,
        718
      ],
      [
        885,
        718
      ],
      [
        885,
        290
      ],
      [
        559,
        290
      ],
      [
        559,
        319
      ]
    ],
    "label": "Consultation / Service Records",
    "labelAt": [
      710,
      287
    ],
    "lines": [
      "Consultation / Service Records"
    ]
  },
  {
    "from": "P7",
    "to": "D2",
    "path": [
      [
        487,
        754
      ],
      [
        866,
        754
      ],
      [
        866,
        490
      ],
      [
        550,
        490
      ],
      [
        550,
        422
      ]
    ],
    "label": "Service Completion Status",
    "labelAt": [
      686,
      487
    ],
    "lines": [
      "Service Completion Status"
    ]
  }
];

const coordinationFlow = flows.find(f => f.from === 'P7' && f.to === 'W');
coordinationFlow.path = [[382,700],[382,600]];
coordinationFlow.labelAt = [382,692];
flows.find(f => f.from === 'P4' && f.to === 'W').labelAt = [235,501];
flows.push({from:'P2',to:'W',path:[[701,550],[460,550],[460,583],[442,583]],label:'Request Assessment Details',labelAt:[574,547],lines:['Request Assessment Details']});
const esc = s => s.replaceAll('&', '&amp;').replaceAll('<', '&lt;');
function text(x,y,lines,cls='',gap=12) {
  return '<text x="'+x+'" y="'+y+'" text-anchor="middle" class="'+cls+'">'+lines.map((s,i)=>'<tspan x="'+x+'" dy="'+(i?gap:0)+'">'+esc(s)+'</tspan>').join('')+'</text>';
}
let svg='<svg xmlns="http://www.w3.org/2000/svg" width="910" height="775" viewBox="0 0 910 775"><defs><marker id="arrow" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0 0L7 3.5L0 7Z" fill="#111"/></marker><style>text{font-family:Arial,sans-serif;font-size:11px;fill:#111}.heading{font-weight:bold}.description{font-size:10px;font-style:italic}.store-name{font-size:6.5px}.label{font-size:9px;paint-order:stroke;stroke:white;stroke-width:4px;stroke-linejoin:round}.flow{fill:none;stroke:#111;stroke-width:0.9;marker-end:url(#arrow)}.box{fill:white;stroke:#111;stroke-width:1}</style></defs><rect width="910" height="775" fill="white"/>';
const segments = flows.flatMap((flow,index) => flow.path.slice(1).map((b,i) => ({a:flow.path[i],b,index})));
const bridges = [];
for (const horizontal of segments.filter(s => s.a[1] === s.b[1])) {
  for (const vertical of segments.filter(s => s.a[0] === s.b[0] && s.index !== horizontal.index)) {
    const x = vertical.a[0], y = horizontal.a[1];
    if (x > Math.min(horizontal.a[0],horizontal.b[0])+4 && x < Math.max(horizontal.a[0],horizontal.b[0])-4 && y > Math.min(vertical.a[1],vertical.b[1])+4 && y < Math.max(vertical.a[1],vertical.b[1])-4) bridges.push([x,y]);
  }
}
for(const f of flows){const d=f.path.map((p,i)=>(i?'L':'M')+p.join(' ')).join(' ');svg+='<path d="'+d+'" fill="none" stroke="white" stroke-width="4"/><path class="flow" d="'+d+'"/>';}
// Explicit jumps distinguish crossing flows without connected or overlapping strokes.
for (const [x,y] of bridges) {
  svg += '<path d="M'+(x-4)+' '+y+'H'+(x+4)+'" stroke="white" stroke-width="2.5"/>';
  const jump = 'M'+(x-4)+' '+y+'C'+(x-4)+' '+(y-6)+' '+(x+4)+' '+(y-6)+' '+(x+4)+' '+y;
  svg += '<path d="'+jump+'" fill="none" stroke="white" stroke-width="3.5"/><path d="'+jump+'" fill="none" stroke="#111" stroke-width="0.9"/>';
}
for(const [id,[x,y,w,h,type,lines]] of Object.entries(nodes)) {
  if(type==='store') svg+='<path class="box" d="M'+(x+w)+' '+y+'H'+x+'V'+(y+h)+'H'+(x+w)+' M'+(x+26)+' '+y+'V'+(y+h)+'"/>'+text(x+13,y+h/2+4,[id])+text(x+26+(w-26)/2,y+h/2-(lines.length-1)*5+3,lines,'store-name',10);
  else {
    svg+='<rect class="box" x="'+x+'" y="'+y+'" width="'+w+'" height="'+h+'"/>';
    if(type==='process') svg+='<path d="M'+x+' '+(y+21)+'H'+(x+w)+'" stroke="#111"/>'+text(x+w/2,y+15,['Process '+id.slice(1)],'heading')+text(x+w/2,y+21+(h-21)/2-(lines.length-1)*6+4,lines,'description');
    else svg+=text(x+w/2,y+h/2-(lines.length-1)*6+4,lines);
  }
}
for(const f of flows)svg+=text(f.labelAt[0],f.labelAt[1],f.lines,'label',11);
svg+='</svg>\n';
fs.writeFileSync(new URL('./capstone-dfd.svg',import.meta.url),svg);
fs.writeFileSync(new URL('./capstone-dfd-layout.json',import.meta.url),JSON.stringify({nodes,flows},null,2));
let mermaid='flowchart LR\n';
for(const [id,n] of Object.entries(nodes)){const label=(n[4]==='process'?'Process '+id.slice(1)+' - ':n[4]==='store'?id+' ':'')+n[5].join(' ');mermaid+='    '+id+(n[4]==='process'?'("'+label+'")':n[4]==='store'?'[("'+label+'")]':'["'+label+'"]')+'\n';}
for(const f of flows)mermaid+='    '+f.from+' -->|'+f.label.replaceAll('&','and')+'| '+f.to+'\n';
fs.writeFileSync(new URL('./capstone-dfd.mmd',import.meta.url),mermaid);
console.log('Created compact 910 x 775 DFD: 14 unique boxes, 31 labeled flows, '+bridges.length+' separated crossing bridges.');
