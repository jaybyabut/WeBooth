// auth-check.js

import { initializeApp } from "https://www.gstatic.com/firebasejs/9.22.0/firebase-app.js";
import { getAuth, onAuthStateChanged } from "https://www.gstatic.com/firebasejs/9.22.0/firebase-auth.js";

// Your Firebase Config (Copy from your signin.html)
const firebaseConfig = {
    apiKey: "AIzaSyBbSE2lqLfQCZU4L4a0hNfpM1ud11I854w",
    authDomain: "photobooth-c42a9.firebaseapp.com",
    projectId: "photobooth-c42a9",
    storageBucket: "photobooth-c42a9.firebasestorage.app",
    messagingSenderId: "994496567051",
    appId: "1:994496567051:web:81d507ace1fbd2db77b40e",
    measurementId: "G-4FHWWBR2BJ"
};

const app = initializeApp(firebaseConfig);
const auth = getAuth(app);

// Get the current page's filename
const currentPage = window.location.pathname.split("/").pop(); 

// Listen for authentication state changes
onAuthStateChanged(auth, (user) => {
    // Define protected pages that require a login
    const protectedPages = ['mainhome.html', 'dashboard.html', 'settings.html', 'finalprof.html'];
    
    // Define unauthorized pages (login/signup) that should redirect when logged in
    const authPages = ['login.html', 'signin.html'];
    
    if (user) {
        // User is signed in.
        
        if (authPages.includes(currentPage)) {
            // If the user is logged in but on the login/signin page, redirect them to mainhome.
            window.location.href = 'mainhome.html';
        }
        // If they are on a protected page (mainhome, etc.), they stay.

    } else {
        // User is signed out.
        
        if (protectedPages.includes(currentPage)) {
            // If they are on a protected page but not logged in, redirect to the login page.
            window.location.href = 'login.html';
        }
        // If they are on a non-protected page (e.g., login.html, signin.html), they stay.
    }
});