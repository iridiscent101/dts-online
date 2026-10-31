importScripts(
  'https://www.gstatic.com/firebasejs/13.0.0/firebase-app-compat.js'
);
importScripts(
  'https://www.gstatic.com/firebasejs/13.0.0/firebase-messaging-compat.js'
);

firebase.initializeApp({
  apiKey: 'AIzaSyDjlDwA6cPc6PyliU4DBcmetCkL-62cOMQ',
  authDomain: 'docutracks-8e181.firebaseapp.com',
  projectId: 'docutracks-8e181',
  messagingSenderId: '962057037287',
  appId: '1:962057037287:web:51749e1e76ff0edcf6c942'
});

firebase.messaging();