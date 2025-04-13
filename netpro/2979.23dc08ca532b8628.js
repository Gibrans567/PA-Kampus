"use strict";(self.webpackChunkfuse=self.webpackChunkfuse||[]).push([[2979],{31849:(x,c,a)=>{a.d(c,{$:()=>w});var p=a(14085),f=a(87122),t=a(54438);const m=[[["","fuseCardFront",""]],[["","fuseCardBack",""]],"*",[["","fuseCardExpansion",""]]],h=["[fuseCardFront]","[fuseCardBack]","*","[fuseCardExpansion]"];function v(e,u){1&e&&(t.j41(0,"div",0),t.SdG(1),t.k0s(),t.j41(2,"div",1),t.SdG(3,1),t.k0s())}function g(e,u){1&e&&(t.j41(0,"div",2),t.SdG(1,3),t.k0s()),2&e&&t.Y8G("@expandCollapse",void 0)}function b(e,u){if(1&e&&(t.SdG(0,2),t.DNE(1,g,2,1,"div",2)),2&e){const r=t.XpG();t.R7$(),t.vxM(r.expanded?1:-1)}}let w=(()=>{class e{constructor(){this.expanded=!1,this.face="front",this.flippable=!1}get classList(){return{"fuse-card-expanded":this.expanded,"fuse-card-face-back":this.flippable&&"back"===this.face,"fuse-card-face-front":this.flippable&&"front"===this.face,"fuse-card-flippable":this.flippable}}ngOnChanges(r){"expanded"in r&&(this.expanded=(0,p.he)(r.expanded.currentValue)),"flippable"in r&&(this.flippable=(0,p.he)(r.flippable.currentValue))}static{this.\u0275fac=function(n){return new(n||e)}}static{this.\u0275cmp=t.VBU({type:e,selectors:[["fuse-card"]],hostVars:2,hostBindings:function(n,l){2&n&&t.HbH(l.classList)},inputs:{expanded:"expanded",face:"face",flippable:"flippable"},exportAs:["fuseCard"],features:[t.OA$],ngContentSelectors:h,decls:2,vars:2,consts:[[1,"fuse-card-front"],[1,"fuse-card-back"],[1,"fuse-card-expansion"]],template:function(n,l){1&n&&(t.NAR(m),t.DNE(0,v,4,0)(1,b,2,1)),2&n&&(t.vxM(l.flippable?0:-1),t.R7$(),t.vxM(l.flippable?-1:1))},styles:["fuse-card{position:relative;display:flex;overflow:hidden;--tw-bg-opacity: 1;background-color:rgba(var(--fuse-bg-card-rgb),var(--tw-bg-opacity));border-radius:1rem;--tw-shadow: 0 1px 3px 0 rgb(0 0 0 / .1), 0 1px 2px -1px rgb(0 0 0 / .1);--tw-shadow-colored: 0 1px 3px 0 var(--tw-shadow-color), 0 1px 2px -1px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow, 0 0 #0000),var(--tw-ring-shadow, 0 0 #0000),var(--tw-shadow)}fuse-card.fuse-card-flippable{border-radius:0;overflow:visible;transform-style:preserve-3d;transition:transform 1s;perspective:600px;background:transparent;--tw-shadow: 0 0 #0000;--tw-shadow-colored: 0 0 #0000;box-shadow:var(--tw-ring-offset-shadow, 0 0 #0000),var(--tw-ring-shadow, 0 0 #0000),var(--tw-shadow)}fuse-card.fuse-card-flippable.fuse-card-face-back .fuse-card-front{visibility:hidden;opacity:0;transform:rotateY(180deg)}fuse-card.fuse-card-flippable.fuse-card-face-back .fuse-card-back{visibility:visible;opacity:1;transform:rotateY(360deg)}fuse-card.fuse-card-flippable .fuse-card-front,fuse-card.fuse-card-flippable .fuse-card-back{display:flex;flex-direction:column;flex:1 1 auto;z-index:10;transition:transform .5s ease-out 0s,visibility 0s ease-in .2s,opacity 0s ease-in .2s;backface-visibility:hidden;--tw-bg-opacity: 1;background-color:rgba(var(--fuse-bg-card-rgb),var(--tw-bg-opacity));border-radius:1rem;--tw-shadow: 0 1px 3px 0 rgb(0 0 0 / .1), 0 1px 2px -1px rgb(0 0 0 / .1);--tw-shadow-colored: 0 1px 3px 0 var(--tw-shadow-color), 0 1px 2px -1px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow, 0 0 #0000),var(--tw-ring-shadow, 0 0 #0000),var(--tw-shadow)}fuse-card.fuse-card-flippable .fuse-card-front{position:relative;opacity:1;visibility:visible;transform:rotateY(0);overflow:hidden}fuse-card.fuse-card-flippable .fuse-card-back{position:absolute;inset:0;opacity:0;visibility:hidden;transform:rotateY(180deg);overflow:hidden auto}\n"],encapsulation:2,data:{animation:f.X}})}}return e})()},72979:(x,c,a)=>{a.r(c),a.d(c,{default:()=>S});var p=a(88834),f=a(99213),t=a(31849),m=a(44742),h=a(60177),v=a(74950),g=a(90882),b=a(21413),w=a(56977),e=a(54438),u=a(96504),r=a(25798),n=a(77502),l=a(95084),F=a(45794),y=a(84412);let C=(()=>{class d{constructor(i){this.userService=i,this.apiUrl=n.c.apiUrl,this.toast=(0,e.WQX)(F.tw),this._deviceSubject=new y.t([]),this.device$=this._deviceSubject.asObservable(),this.initMqtt()}initMqtt(){this.client=l.A.connect("wss://netpro.awh.co.id:8083/mqtt",{username:n.c.mqtt.username,password:n.c.mqtt.password}),this.client.on("connect",()=>{console.log("MQTT connected"),this.userService.topic$.subscribe(i=>{this.client.subscribe(`${"Netpro"===i?"mikrotik":i}/get-device`,o=>{o&&(console.error("Failed to subscribe:",o),this.toast.error(o.message,"Error Subscribing"))})})}),this.client.on("message",(i,o)=>{this.userService.topic$.subscribe(s=>{i===`${"Netpro"===s?"mikrotik":s}/get-device`&&this.handleHotspotProfileMessage(o.toString())})}),this.client.on("close",()=>{console.warn("MQTT connection closed, attempting to reconnect..."),setTimeout(()=>{this.client.reconnect()},1e3)})}handleHotspotProfileMessage(i){try{const o=JSON.parse(i);this._deviceSubject.next(o)}catch(o){console.error("Error parsing message:",o),this.toast.error("Invalid data received","Error")}}disconnect(){this.client&&this.client.end(!0)}static{this.\u0275fac=function(o){return new(o||d)(e.KVO(r.D))}}static{this.\u0275prov=e.jDH({token:d,factory:d.\u0275fac,providedIn:"root"})}}return d})();(0,v.u2)(window);const S=[{path:"",component:(()=>{class d{constructor(i,o,s){this.mqttService=i,this.userService=o,this.homeService=s,this.unsubscribe$=new b.B}ngOnInit(){this.homeService.device$.pipe((0,w.Q)(this.unsubscribe$)).subscribe(i=>{this.message=i})}ngOnDestroy(){this.unsubscribe$.next(),this.unsubscribe$.complete(),this.homeService.disconnect()}getFormattedMemory(i){const o=parseFloat(i)/1048576;return o>=1e3?(o/1024).toFixed(1)+" GiB":o.toFixed(1)+" MiB"}static{this.\u0275fac=function(o){return new(o||d)(e.rXU(u.s),e.rXU(r.D),e.rXU(C))}}static{this.\u0275cmp=e.VBU({type:d,selectors:[["app-home"]],decls:48,vars:10,consts:[["fuseCard",""],[1,"w-full","h-fit","pb-13"],[1,"dark","relative","flex-0","overflow-hidden","bg-[url('assets/img/home-bg.png')]","px-4","sm:p-16"],[1,"relative","z-10","flex","flex-col","items-center","py-20"],[1,"text-4xl","font-bold"],[1,"text-secondary","font-semibold","max-w-2xl","tracking-tight","sm:text-2xl"],[1,"grid-cols-1","md:grid-cols-2","lg:grid-cols-2","grid","px-4","py-4","gap-4"],[1,"flex","flex-col","px-8","py-6","pb-4"],[1,"flex","items-center","gap-5"],["svgIcon","heroicons_solid:calendar-days",2,"width","48px","height","48px"],[1,"mt-2"],[1,"font-medium"],["svgIcon","heroicons_solid:information-circle",2,"width","48px","height","48px"],["svgIcon","heroicons_solid:cpu-chip",2,"width","48px","height","48px"],[1,"flex","gap-2"]],template:function(o,s){1&o&&(e.j41(0,"div",1)(1,"div",2)(2,"div",3)(3,"h2",4),e.EFF(4,"NetPro Connect"),e.k0s(),e.j41(5,"div",5),e.EFF(6," Empower Your Network Control in Your Hands "),e.k0s()()(),e.j41(7,"div",6)(8,"fuse-card",7,0)(10,"section",8),e.nrm(11,"mat-icon",9),e.j41(12,"div",10)(13,"p",11),e.EFF(14),e.k0s(),e.j41(15,"p",11),e.EFF(16),e.k0s(),e.j41(17,"p",11),e.EFF(18),e.k0s(),e.j41(19,"p",11),e.EFF(20),e.k0s()()()(),e.j41(21,"fuse-card",7,0)(23,"section",8),e.nrm(24,"mat-icon",12),e.j41(25,"div",10)(26,"p",11),e.EFF(27),e.k0s(),e.j41(28,"p",11),e.EFF(29),e.k0s(),e.j41(30,"p",11),e.EFF(31),e.k0s()()()(),e.j41(32,"fuse-card",7,0)(34,"section",8),e.nrm(35,"mat-icon",13),e.j41(36,"div",10)(37,"p",11),e.EFF(38),e.k0s(),e.j41(39,"p",11),e.EFF(40),e.k0s(),e.j41(41,"p",11),e.EFF(42),e.k0s(),e.j41(43,"div",14)(44,"p",11),e.EFF(45,"Voltage: 24.7V"),e.k0s(),e.j41(46,"p",11),e.EFF(47,"Temp: 31C"),e.k0s()()()()()()()),2&o&&(e.R7$(14),e.SpI("Date : ",null!=s.message&&s.message.date?s.message.date:"Memuat Data...",""),e.R7$(2),e.SpI("Time : ",null!=s.message&&s.message.time?s.message.time:"Memuat Data...",""),e.R7$(2),e.SpI("Uptime : ",null!=s.message&&s.message.upTime?s.message.upTime:"Memuat Data...",""),e.R7$(2),e.SpI("Time Zone : ",null!=s.message&&s.message.upTime?s.message.upTime:"Memuat Data...",""),e.R7$(7),e.SpI("Board Name : ",s.message.boardName?s.message.boardName:"Memuat data...",""),e.R7$(2),e.SpI("Model : ",null!=s.message&&s.message.model?s.message.model:"Memuat Data...",""),e.R7$(2),e.SpI("Router OS : ",null!=s.message&&s.message.rosVersion?s.message.rosVersion:"Memuat Data..."," "),e.R7$(7),e.SpI("CPU Load : ",null!=s.message&&s.message.cpuLoad?s.message.cpuLoad:"Memuat Data...","%"),e.R7$(2),e.SpI("Free Memory : ",s.getFormattedMemory(null!=s.message&&s.message.freeMemory?s.message.freeMemory:"Memuat Data..."),""),e.R7$(2),e.SpI("Free HDD : ",null!=s.message&&s.message.freeHdd?s.message.freeHdd+" kib":"Memuat Data..."," "))},dependencies:[t.$,f.m_,f.An,p.Hl,m.bv,h.MD,g.vg],encapsulation:2})}}return d})()}]}}]);