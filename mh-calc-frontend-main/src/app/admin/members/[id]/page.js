'use client';
import React from 'react';
import { useParams } from 'next/navigation';
import MemberCard from '@/views/admin/MemberCard';

export default function AdminMemberPage() {
    const { id } = useParams();
    return <MemberCard id={id} />;
}
