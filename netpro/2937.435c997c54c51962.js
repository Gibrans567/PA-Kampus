"use strict";(self.webpackChunkfuse=self.webpackChunkfuse||[]).push([[2937],{32937:(q,P,v)=>{v.r(P),v.d(P,{FilesystemWeb:()=>F});var l=v(10467),x=v(15083),A=v(11889);function R(y){const w=y.split("/").filter(t=>"."!==t),n=[];return w.forEach(t=>{".."===t&&n.length>0&&".."!==n[n.length-1]?n.pop():n.push(t)}),n.join("/")}let F=(()=>{class y extends x.E_{constructor(){var n;super(...arguments),n=this,this.DB_VERSION=1,this.DB_NAME="Disc",this._writeCmds=["add","put","delete"],this.downloadFile=function(){var t=(0,l.A)(function*(r){var e,o;const d=(0,x.EA)(r,r.webFetchExtra),i=yield fetch(r.url,d);let a;if(r.progress)if(i?.body){const c=i.body.getReader();let u=0;const h=[],m=i.headers.get("content-type"),p=parseInt(i.headers.get("content-length")||"0",10);for(;;){const{done:g,value:_}=yield c.read();if(g)break;h.push(_),u+=_?.length||0,n.notifyListeners("progress",{url:r.url,bytes:u,contentLength:p})}const f=new Uint8Array(u);let b=0;for(const g of h)typeof g>"u"||(f.set(g,b),b+=g.length);a=new Blob([f.buffer],{type:m||void 0})}else a=new Blob;else a=yield i.blob();return{path:(yield n.writeFile({path:r.path,directory:null!==(e=r.directory)&&void 0!==e?e:void 0,recursive:null!==(o=r.recursive)&&void 0!==o&&o,data:a})).uri,blob:a}});return function(r){return t.apply(this,arguments)}}()}initDb(){var n=this;return(0,l.A)(function*(){if(void 0!==n._db)return n._db;if(!("indexedDB"in window))throw n.unavailable("This browser doesn't support IndexedDB");return new Promise((t,r)=>{const e=indexedDB.open(n.DB_NAME,n.DB_VERSION);e.onupgradeneeded=y.doUpgrade,e.onsuccess=()=>{n._db=e.result,t(e.result)},e.onerror=()=>r(e.error),e.onblocked=()=>{console.warn("db blocked")}})})()}static doUpgrade(n){const r=n.target.result;r.objectStoreNames.contains("FileStorage")&&r.deleteObjectStore("FileStorage"),r.createObjectStore("FileStorage",{keyPath:"path"}).createIndex("by_folder","folder")}dbRequest(n,t){var r=this;return(0,l.A)(function*(){const e=-1!==r._writeCmds.indexOf(n)?"readwrite":"readonly";return r.initDb().then(o=>new Promise((d,i)=>{const c=o.transaction(["FileStorage"],e).objectStore("FileStorage")[n](...t);c.onsuccess=()=>d(c.result),c.onerror=()=>i(c.error)}))})()}dbIndexRequest(n,t,r){var e=this;return(0,l.A)(function*(){const o=-1!==e._writeCmds.indexOf(t)?"readwrite":"readonly";return e.initDb().then(d=>new Promise((i,a)=>{const h=d.transaction(["FileStorage"],o).objectStore("FileStorage").index(n)[t](...r);h.onsuccess=()=>i(h.result),h.onerror=()=>a(h.error)}))})()}getPath(n,t){const r=void 0!==t?t.replace(/^[/]+|[/]+$/g,""):"";let e="";return void 0!==n&&(e+="/"+n),""!==t&&(e+="/"+r),e}clear(){var n=this;return(0,l.A)(function*(){(yield n.initDb()).transaction(["FileStorage"],"readwrite").objectStore("FileStorage").clear()})()}readFile(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path),e=yield t.dbRequest("get",[r]);if(void 0===e)throw Error("File does not exist.");return{data:e.content?e.content:""}})()}writeFile(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path);let e=n.data;const o=n.encoding,d=n.recursive,i=yield t.dbRequest("get",[r]);if(i&&"directory"===i.type)throw Error("The supplied path is a directory.");const a=r.substr(0,r.lastIndexOf("/"));if(void 0===(yield t.dbRequest("get",[a]))){const h=a.indexOf("/",1);if(-1!==h){const m=a.substr(h);yield t.mkdir({path:m,directory:n.directory,recursive:d})}}if(!(o||e instanceof Blob||(e=e.indexOf(",")>=0?e.split(",")[1]:e,t.isBase64String(e))))throw Error("The supplied data is not valid base64 content.");const c=Date.now(),u={path:r,folder:a,type:"file",size:e instanceof Blob?e.size:e.length,ctime:c,mtime:c,content:e};return yield t.dbRequest("put",[u]),{uri:u.path}})()}appendFile(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path);let e=n.data;const o=n.encoding,d=r.substr(0,r.lastIndexOf("/")),i=Date.now();let a=i;const s=yield t.dbRequest("get",[r]);if(s&&"directory"===s.type)throw Error("The supplied path is a directory.");if(void 0===(yield t.dbRequest("get",[d]))){const h=d.indexOf("/",1);if(-1!==h){const m=d.substr(h);yield t.mkdir({path:m,directory:n.directory,recursive:!0})}}if(!o&&!t.isBase64String(e))throw Error("The supplied data is not valid base64 content.");if(void 0!==s){if(s.content instanceof Blob)throw Error("The occupied entry contains a Blob object which cannot be appended to.");e=void 0===s.content||o?s.content+e:btoa(atob(s.content)+atob(e)),a=s.ctime}const u={path:r,folder:d,type:"file",size:e.length,ctime:a,mtime:i,content:e};yield t.dbRequest("put",[u])})()}deleteFile(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path);if(void 0===(yield t.dbRequest("get",[r])))throw Error("File does not exist.");if(0!==(yield t.dbIndexRequest("by_folder","getAllKeys",[IDBKeyRange.only(r)])).length)throw Error("Folder is not empty.");yield t.dbRequest("delete",[r])})()}mkdir(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path),e=n.recursive,o=r.substr(0,r.lastIndexOf("/")),d=(r.match(/\//g)||[]).length,i=yield t.dbRequest("get",[o]),a=yield t.dbRequest("get",[r]);if(1===d)throw Error("Cannot create Root directory");if(void 0!==a)throw Error("Current directory does already exist.");if(!e&&2!==d&&void 0===i)throw Error("Parent directory must exist");if(e&&2!==d&&void 0===i){const u=o.substr(o.indexOf("/",1));yield t.mkdir({path:u,directory:n.directory,recursive:e})}const s=Date.now(),c={path:r,folder:o,type:"directory",size:0,ctime:s,mtime:s};yield t.dbRequest("put",[c])})()}rmdir(n){var t=this;return(0,l.A)(function*(){const{path:r,directory:e,recursive:o}=n,d=t.getPath(e,r),i=yield t.dbRequest("get",[d]);if(void 0===i)throw Error("Folder does not exist.");if("directory"!==i.type)throw Error("Requested path is not a directory");const a=yield t.readdir({path:r,directory:e});if(0!==a.files.length&&!o)throw Error("Folder is not empty");for(const s of a.files){const c=`${r}/${s.name}`;"file"===(yield t.stat({path:c,directory:e})).type?yield t.deleteFile({path:c,directory:e}):yield t.rmdir({path:c,directory:e,recursive:o})}yield t.dbRequest("delete",[d])})()}readdir(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path),e=yield t.dbRequest("get",[r]);if(""!==n.path&&void 0===e)throw Error("Folder does not exist.");const o=yield t.dbIndexRequest("by_folder","getAllKeys",[IDBKeyRange.only(r)]);return{files:yield Promise.all(o.map(function(){var i=(0,l.A)(function*(a){let s=yield t.dbRequest("get",[a]);return void 0===s&&(s=yield t.dbRequest("get",[a+"/"])),{name:a.substring(r.length+1),type:s.type,size:s.size,ctime:s.ctime,mtime:s.mtime,uri:s.path}});return function(a){return i.apply(this,arguments)}}()))}})()}getUri(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path);let e=yield t.dbRequest("get",[r]);return void 0===e&&(e=yield t.dbRequest("get",[r+"/"])),{uri:e?.path||r}})()}stat(n){var t=this;return(0,l.A)(function*(){const r=t.getPath(n.directory,n.path);let e=yield t.dbRequest("get",[r]);if(void 0===e&&(e=yield t.dbRequest("get",[r+"/"])),void 0===e)throw Error("Entry does not exist.");return{type:e.type,size:e.size,ctime:e.ctime,mtime:e.mtime,uri:e.path}})()}rename(n){var t=this;return(0,l.A)(function*(){yield t._copy(n,!0)})()}copy(n){var t=this;return(0,l.A)(function*(){return t._copy(n,!1)})()}requestPermissions(){return(0,l.A)(function*(){return{publicStorage:"granted"}})()}checkPermissions(){return(0,l.A)(function*(){return{publicStorage:"granted"}})()}_copy(n,t=!1){var r=this;return(0,l.A)(function*(){let{toDirectory:e}=n;const{to:o,from:d,directory:i}=n;if(!o||!d)throw Error("Both to and from must be provided");e||(e=i);const a=r.getPath(i,d),s=r.getPath(e,o);if(a===s)return{uri:s};if(function D(y,w){y=R(y),w=R(w);const n=y.split("/"),t=w.split("/");return y!==w&&n.every((r,e)=>r===t[e])}(a,s))throw Error("To path cannot contain the from path");let c;try{c=yield r.stat({path:o,directory:e})}catch{const f=o.split("/");f.pop();const b=f.join("/");if(f.length>0&&"directory"!==(yield r.stat({path:b,directory:e})).type)throw new Error("Parent directory of the to path is a file")}if(c&&"directory"===c.type)throw new Error("Cannot overwrite a directory with a file");const u=yield r.stat({path:d,directory:i}),h=function(){var p=(0,l.A)(function*(f,b,g){const _=r.getPath(e,f),E=yield r.dbRequest("get",[_]);E.ctime=b,E.mtime=g,yield r.dbRequest("put",[E])});return function(b,g,_){return p.apply(this,arguments)}}(),m=u.ctime?u.ctime:Date.now();switch(u.type){case"file":{const p=yield r.readFile({path:d,directory:i});let f;t&&(yield r.deleteFile({path:d,directory:i})),!(p.data instanceof Blob)&&!r.isBase64String(p.data)&&(f=A.Wi.UTF8);const b=yield r.writeFile({path:o,directory:e,data:p.data,encoding:f});return t&&(yield h(o,m,u.mtime)),b}case"directory":{if(c)throw Error("Cannot move a directory over an existing object");try{yield r.mkdir({path:o,directory:e,recursive:!1}),t&&(yield h(o,m,u.mtime))}catch{}const p=(yield r.readdir({path:d,directory:i})).files;for(const f of p)yield r._copy({from:`${d}/${f.name}`,to:`${o}/${f.name}`,directory:i,toDirectory:e},t);t&&(yield r.rmdir({path:d,directory:i}))}}return{uri:s}})()}isBase64String(n){try{return btoa(atob(n))==n}catch{return!1}}}return y._debug=!0,y})()}}]);