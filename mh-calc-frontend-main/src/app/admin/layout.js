'use client';
import React from 'react';
import AdminLayout from '@/views/admin/AdminLayout';

export default function Layout({ children }) {
    return <AdminLayout>{children}</AdminLayout>;
}
