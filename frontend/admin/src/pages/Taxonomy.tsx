import { Button, Card, Form, Input, InputNumber, message, Modal, Popconfirm, Select, Space, Table, Tabs, Tag } from 'antd'
import type { Key } from 'react'
import { useEffect, useMemo, useState } from 'react'
import { api } from '../api'

/** 批量执行：逐项调用，汇总成功/失败 */
async function runBatch(
  ids: Key[],
  op: (row: any) => Promise<any>,
  rows: any[],
  keyField: string,
  done: () => void,
) {
  let okN = 0
  let failN = 0
  const errs: string[] = []
  for (const id of ids) {
    const row = rows.find((r) => r[keyField] === id)
    try {
      await op(row)
      okN++
    } catch (e: any) {
      failN++
      if (errs.length < 2) errs.push(`${row?.[keyField] ?? id}: ${e.message}`)
    }
  }
  const label = errs.length === 0 ? `成功（${okN} 项）` : `成功 ${okN} 项，失败 ${failN} 项：${errs.join('；')}`
  if (failN === 0) message.success(`批量操作${label}`)
  else message.warning(label)
  done()
}

function CategoriesTab() {
  const [list, setList] = useState<any[]>([])
  const [kw, setKw] = useState('')
  const [rootFilter, setRootFilter] = useState<string>('')
  const [sel, setSel] = useState<Key[]>([])
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<any>(null)
  const [form] = Form.useForm()

  const load = () => api('/admin/categories').then((d) => setList(d.list ?? []))
  useEffect(() => {
    load()
  }, [])

  const roots = list.filter((c) => c.parent_id === '0')

  // 联动筛选：选一级 → 只显示该一级及其二级；再加关键词
  const filtered = useMemo(() => {
    let out = list
    if (rootFilter) out = out.filter((c) => c.id === rootFilter || c.parent_id === rootFilter)
    if (kw) out = out.filter((c) => (c.name ?? '').includes(kw) || (c.id ?? '').includes(kw))
    return out
  }, [list, kw, rootFilter])

  const save = async () => {
    const v = await form.validateFields()
    try {
      await api('/admin/categories', { method: editing ? 'PUT' : 'POST', body: editing ? { ...v, id: editing.id } : v })
      message.success('已保存')
      setOpen(false)
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const batch = (op: 'on' | 'off' | 'delete') =>
    runBatch(
      sel,
      async (row) => {
        if (op === 'delete') {
          await api(`/admin/categories/${row.id}`, { method: 'DELETE' })
        } else {
          if (row.status === op) return
          await api('/admin/categories', { method: 'PUT', body: { id: row.id, name: row.name, status: op, sort: row.sort ?? 0 } })
        }
      },
      list,
      'id',
      () => {
        setSel([])
        load()
      },
    )

  const columns = [
    { title: 'ID', dataIndex: 'id', width: 120 },
    { title: '名称', dataIndex: 'name' },
    {
      title: '层级',
      render: (_: unknown, r: any) =>
        r.parent_id === '0' ? '一级' : `二级 → ${roots.find((p) => p.id === r.parent_id)?.name ?? r.parent_id}`,
    },
    { title: '排序', dataIndex: 'sort', width: 80 },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (s: string) => <Tag color={s === 'on' ? 'green' : 'default'}>{s === 'on' ? '启用' : '停用'}</Tag>,
    },
    {
      title: '操作',
      width: 140,
      render: (_: unknown, r: any) => (
        <Space>
          <a
            onClick={() => {
              setEditing(r)
              form.setFieldsValue(r)
              setOpen(true)
            }}
          >
            编辑
          </a>
          <Popconfirm title="确认删除？" onConfirm={() => runBatch([r.id], (x) => api(`/admin/categories/${x.id}`, { method: 'DELETE' }), list, 'id', load)}>
            <a style={{ color: '#cf1322' }}>删除</a>
          </Popconfirm>
        </Space>
      ),
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Input
          placeholder="按名称/ID 搜索"
          allowClear
          value={kw}
          onChange={(e) => setKw(e.target.value)}
          style={{ width: 180 }}
        />
        <Select
          placeholder="按一级分类联动筛选"
          allowClear
          style={{ width: 180 }}
          value={rootFilter || undefined}
          onChange={(v) => setRootFilter(v ?? '')}
          options={roots.map((r) => ({ value: r.id, label: r.name }))}
        />
        <Button type="primary" onClick={() => { setEditing(null); form.resetFields(); setOpen(true) }}>
          新增分类
        </Button>
      </Space>

      {sel.length > 0 && (
        <Space style={{ marginBottom: 12, padding: '8px 12px', background: '#f0f7ff', borderRadius: 6, width: '100%' }}>
          <span>已选 <b>{sel.length}</b> 项</span>
          <Button size="small" type="primary" onClick={() => batch('on')}>批量启用</Button>
          <Button size="small" onClick={() => batch('off')}>批量停用</Button>
          <Popconfirm title="确认删除所选？（有子分类/素材的会被拒绝）" onConfirm={() => batch('delete')}>
            <Button size="small" danger>批量删除</Button>
          </Popconfirm>
          <Button size="small" type="text" onClick={() => setSel([])}>取消选择</Button>
        </Space>
      )}

      <Table
        rowKey="id"
        dataSource={filtered}
        columns={columns}
        pagination={false}
        rowSelection={{ selectedRowKeys: sel, onChange: (k) => setSel(k) }}
      />

      <Modal title={editing ? '编辑分类' : '新增分类'} open={open} onOk={save} onCancel={() => setOpen(false)} destroyOnClose>
        <Form form={form} layout="vertical" initialValues={{ parent_id: '0', status: 'on', sort: 0 }}>
          <Form.Item name="name" label="名称" rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Form.Item name="parent_id" label="层级">
            <Select
              options={[
                { value: '0', label: '作为一级分类' },
                ...roots.filter((r) => !editing || r.id !== editing.id).map((r) => ({ value: r.id, label: `${r.name}（下）` })),
              ]}
            />
          </Form.Item>
          <Form.Item name="sort" label="排序（小的在前）">
            <InputNumber min={0} />
          </Form.Item>
          <Form.Item name="status" label="状态">
            <Select options={[{ value: 'on', label: '启用' }, { value: 'off', label: '停用' }]} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  )
}

function TagsTab() {
  const [list, setList] = useState<any[]>([])
  const [kw, setKw] = useState('')
  const [typeFilter, setTypeFilter] = useState<string>('')
  const [sel, setSel] = useState<Key[]>([])
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<any>(null)
  const [form] = Form.useForm()

  const load = () => api('/admin/tags').then((d) => setList(d.list ?? []))
  useEffect(() => {
    load()
  }, [])

  const filtered = useMemo(() => {
    let out = list
    if (typeFilter) out = out.filter((t) => (t.type ?? 'channel') === typeFilter)
    if (kw) out = out.filter((t) => (t.name ?? '').includes(kw) || (t.key ?? '').includes(kw))
    return out
  }, [list, kw, typeFilter])

  const save = async () => {
    const v = await form.validateFields()
    try {
      await api('/admin/tags', { method: editing ? 'PUT' : 'POST', body: v })
      message.success('已保存')
      setOpen(false)
      load()
    } catch (e: any) {
      message.error(e.message)
    }
  }

  const batch = (op: 'on' | 'off' | 'delete') =>
    runBatch(
      sel,
      async (row) => {
        if (op === 'delete') {
          await api(`/admin/tags/${row.key}`, { method: 'DELETE' })
        } else {
          if (row.status === op) return
          await api('/admin/tags', { method: 'PUT', body: { key: row.key, name: row.name, status: op, guide: row.guide ?? '', type: row.type ?? 'channel' } })
        }
      },
      list,
      'key',
      () => {
        setSel([])
        load()
      },
    )

  const columns = [
    { title: 'key', dataIndex: 'key', width: 120 },
    { title: '名称', dataIndex: 'name', width: 120 },
    { title: '类型', dataIndex: 'type', width: 100, render: (t: string) => (t === 'channel' ? '渠道' : t) },
    { title: '发布引导文案', dataIndex: 'guide', ellipsis: true },
    {
      title: '状态',
      dataIndex: 'status',
      width: 90,
      render: (s: string) => <Tag color={s === 'on' ? 'green' : 'default'}>{s === 'on' ? '启用' : '停用'}</Tag>,
    },
    {
      title: '操作',
      width: 140,
      render: (_: unknown, r: any) => (
        <Space>
          <a
            onClick={() => {
              setEditing(r)
              form.setFieldsValue(r)
              setOpen(true)
            }}
          >
            编辑
          </a>
          <Popconfirm title="确认删除？" onConfirm={() => runBatch([r.key], (x) => api(`/admin/tags/${x.key}`, { method: 'DELETE' }), list, 'key', load)}>
            <a style={{ color: '#cf1322' }}>删除</a>
          </Popconfirm>
        </Space>
      ),
    },
  ]

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <Input placeholder="按名称/key 搜索" allowClear value={kw} onChange={(e) => setKw(e.target.value)} style={{ width: 180 }} />
        <Select
          placeholder="类型"
          allowClear
          style={{ width: 140 }}
          value={typeFilter || undefined}
          onChange={(v) => setTypeFilter(v ?? '')}
          options={[{ value: 'channel', label: '渠道' }, { value: 'other', label: '其他' }]}
        />
        <Button type="primary" onClick={() => { setEditing(null); form.resetFields(); setOpen(true) }}>
          新增标签
        </Button>
      </Space>

      {sel.length > 0 && (
        <Space style={{ marginBottom: 12, padding: '8px 12px', background: '#f0f7ff', borderRadius: 6, width: '100%' }}>
          <span>已选 <b>{sel.length}</b> 项</span>
          <Button size="small" type="primary" onClick={() => batch('on')}>批量启用</Button>
          <Button size="small" onClick={() => batch('off')}>批量停用</Button>
          <Popconfirm title="确认删除所选？（被素材引用的会被拒绝）" onConfirm={() => batch('delete')}>
            <Button size="small" danger>批量删除</Button>
          </Popconfirm>
          <Button size="small" type="text" onClick={() => setSel([])}>取消选择</Button>
        </Space>
      )}

      <Table
        rowKey="key"
        dataSource={filtered}
        columns={columns}
        pagination={false}
        rowSelection={{ selectedRowKeys: sel, onChange: (k) => setSel(k) }}
      />

      <Modal title={editing ? '编辑标签' : '新增标签'} open={open} onOk={save} onCancel={() => setOpen(false)} destroyOnClose>
        <Form form={form} layout="vertical" initialValues={{ type: 'channel', status: 'on' }}>
          <Form.Item
            name="key"
            label="key（唯一标识，创建后不可改）"
            rules={[{ required: !editing, pattern: /^[a-z][a-z0-9_]{1,19}$/, message: '小写字母开头，2~20 位' }]}
          >
            <Input disabled={!!editing} placeholder="xhs" />
          </Form.Item>
          <Form.Item name="name" label="展示名" rules={[{ required: true }]}>
            <Input placeholder="小红书" />
          </Form.Item>
          <Form.Item name="type" label="类型">
            <Select options={[{ value: 'channel', label: '渠道' }, { value: 'other', label: '其他（预留）' }]} />
          </Form.Item>
          <Form.Item name="guide" label="发布引导文案（提交凭证页展示）">
            <Input.TextArea rows={3} />
          </Form.Item>
          <Form.Item name="status" label="状态">
            <Select options={[{ value: 'on', label: '启用' }, { value: 'off', label: '停用' }]} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  )
}

export default function Taxonomy() {
  return (
    <Card title="分类与标签">
      <Tabs
        items={[
          { key: 'cat', label: '分类', children: <CategoriesTab /> },
          { key: 'tag', label: '标签', children: <TagsTab /> },
        ]}
      />
    </Card>
  )
}
