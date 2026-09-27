import { Link } from 'react-router'

export function InventoryHomePage() {
  return (
    <>
      <h1 className="text-2xl font-bold tracking-tight">備品在庫管理</h1>
      <p className="mt-2 text-slate-600">M1で実装します</p>
      <Link to="/" className="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
        ポータルに戻る
      </Link>
    </>
  )
}