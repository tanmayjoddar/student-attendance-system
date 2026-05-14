<div style="font-family: Arial, Helvetica, sans-serif; font-size:11px;">
    <style>
        @page {
            margin: 18px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .nowrap {
            white-space: nowrap;
        }
    </style>

    <h2 style="margin:0 0 6px 0;">Students Export</h2>
    <div style="margin:0 0 12px 0; color:#666;">
        Date range: {{ optional($fromDate)->format('Y-m-d') ?? 'N/A' }} to {{ optional($toDate)->format('Y-m-d') ?? 'N/A' }}
    </div>
    <table>
        <thead>
            <tr>
                <th style="width:9%;">Student ID</th>
                <th style="width:10%;">First Name</th>
                <th style="width:10%;">Last Name</th>
                <th style="width:18%;">Email</th>
                <th style="width:11%;">Phone</th>
                <th style="width:12%;">Department</th>
                <th style="width:20%;">Location / Address</th>
                <th style="width:8%;">Active</th>
                <th style="width:12%;">Registered At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $s)
                <tr>
                    <td>{{ $s->student_id }}</td>
                    <td>{{ $s->first_name }}</td>
                    <td>{{ $s->last_name }}</td>
                    <td>{{ $s->email }}</td>
                    <td>{{ $s->phone ?: 'N/A' }}</td>
                    <td>{{ $s->department ?: 'N/A' }}</td>
                    <td>{{ $s->address ?: 'N/A' }}</td>
                    <td>{{ $s->is_active ? 'Yes' : 'No' }}</td>
                    <td class="nowrap">{{ optional($s->created_at)->format('Y-m-d H:i:s') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
