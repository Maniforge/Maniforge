import type { ReactNode } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { DeskHeader } from '@maniforge/desk-ui';
import { HomePage } from './pages/HomePage';
import { AboutPage } from './pages/AboutPage';
import { AboutUsPage } from './pages/AboutUsPage';
import { ApiDocsPage } from './pages/ApiDocsPage';
import { LoginPage } from './pages/LoginPage';
import { DeskHomePage } from './pages/DeskHomePage';
import { UsersPage } from './pages/UsersPage';

function Shell({ children }: { children: ReactNode }) {
  return (
    <>
      <DeskHeader />
      {children}
    </>
  );
}

export function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Shell><HomePage /></Shell>} />
        <Route path="/about" element={<Shell><AboutPage /></Shell>} />
        <Route path="/about/" element={<Shell><AboutPage /></Shell>} />
        <Route path="/about-us" element={<Shell><AboutUsPage /></Shell>} />
        <Route path="/about-us/" element={<Shell><AboutUsPage /></Shell>} />
        <Route path="/api" element={<Shell><ApiDocsPage /></Shell>} />
        <Route path="/api/" element={<Shell><ApiDocsPage /></Shell>} />
        <Route path="/desk/login" element={<Shell><LoginPage /></Shell>} />
        <Route path="/desk/login/" element={<Shell><LoginPage /></Shell>} />
        <Route path="/desk" element={<Shell><DeskHomePage /></Shell>} />
        <Route path="/desk/" element={<Shell><DeskHomePage /></Shell>} />
        <Route path="/desk/users" element={<Shell><UsersPage /></Shell>} />
        <Route path="/desk/users/" element={<Shell><UsersPage /></Shell>} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}
