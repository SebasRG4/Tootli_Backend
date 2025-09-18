importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-app.js');
importScripts('https://www.gstatic.com/firebasejs/8.3.2/firebase-messaging.js');

firebase.initializeApp({
    apiKey: "AIzaSyDjjgzQ6CsEbwV4XbsOxaAC0k5YY4KLoLE",
    authDomain: "tootli-74a7c.firebaseapp.com",
    projectId: "tootli-74a7c",
    storageBucket: "tootli-74a7c.firebasestorage.app",
    messagingSenderId: "475625875675",
    appId: "1:475625875675:web:18e7b56e162c5cd18cc4fe",
    measurementId: "G-03Q4BKRMQV"
});

const messaging = firebase.messaging();
messaging.setBackgroundMessageHandler(function (payload) {
    return self.registration.showNotification(payload.data.title, {
        body: payload.data.body ? payload.data.body : '',
        icon: payload.data.icon ? payload.data.icon : ''
    });
});